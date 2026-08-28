<?php
/**
 * Programs list (admin)
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
use Headcount\Services\ProgramService;

// Auth is already handled by index.php router; only call it on direct access
if (empty($_SESSION['user_id'])) {
    AuthMiddleware::requireAdminOrCoordinator();
}
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$canMaintainPrograms = AuthMiddleware::isAdmin() || AuthMiddleware::can('programs.manage');

$db = Database::getInstance();
$userData = $db->queryOne("SELECT first_name, last_name, email, role FROM users WHERE id = :id", ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

$programs = [];
$programCategories = [];
$tableOk = false;
$programsError = null;
$statusFilter = get('status', 'all');
$categoryFilter = get('category', 'all');
$searchPrograms = get('search', '');
$listFilters = [];
if ($statusFilter !== 'all') {
    $listFilters['status'] = $statusFilter;
}
if ($categoryFilter !== 'all' && ctype_digit((string) $categoryFilter)) {
    $listFilters['category_id'] = (int) $categoryFilter;
}
if ($searchPrograms !== '') {
    $listFilters['search'] = $searchPrograms;
}
$hasListFilters = $statusFilter !== 'all' || $categoryFilter !== 'all' || $searchPrograms !== '';
try {
    $svc = new ProgramService();
    $tableOk = $svc->tableExists('programs');
    if ($tableOk) {
        if (AuthMiddleware::isPresenter()) {
            $listFilters['presenter_user_id'] = $userId;
        }
        $programs = $svc->listForOrg($organizationId, $listFilters);
        $programCategories = $svc->listCategories($organizationId);
    }
} catch (\Exception $e) {
    $programsError = $e->getMessage();
}

require_once __DIR__ . '/includes/layout-vars.php';
$apiPrograms = $basePath . '/public/api/programs.php';
$csrfToken = CsrfMiddleware::getToken();

$pageTitle = 'Programs';
$currentPage = 'programs';
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in" x-data="programsPageApp()" x-init="init()">
    <?php
    $pageHeaderTitle = 'Programs';
    $pageHeaderSubtitle = 'Manage classes, halaqahs, and recurring offerings.';
    ob_start();
    if ($tableOk): ?>
    <div class="flex flex-wrap items-center gap-2 sm:gap-3" role="group" aria-label="View mode">
        <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1 dark:bg-gray-800">
            <button type="button" @click="viewMode = 'card'; saveViewPreference('card')" :class="viewMode === 'card' ? 'bg-white text-brand-600 shadow-sm ring-1 ring-brand-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60'" class="px-3 py-2 rounded-lg transition-all font-bold text-sm" title="Card view">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
            </button>
            <button type="button" @click="viewMode = 'table'; saveViewPreference('table')" :class="viewMode === 'table' ? 'bg-white text-brand-600 shadow-sm ring-1 ring-brand-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60'" class="px-3 py-2 rounded-lg transition-all font-bold text-sm" title="Table view">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
        </div>
    </div>
    <?php if ($can('programs.manage')): ?>
    <button type="button" @click="openCatModal()" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0">Program categories</button>
    <?php endif; ?>
    <?php if ($can('programs.request')): ?>
    <a href="<?= e($adminBase . '/index.php?page=program-request-form') ?>" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0">Request Program</a>
    <?php endif; ?>
    <?php if ($can('programs.manage')): ?>
    <a href="<?= e($adminBase . '/index.php?page=program-edit') ?>" class="page-header-btn-primary whitespace-nowrap flex-shrink-0">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        New Program
    </a>
    <?php endif; ?>
    <?php endif;
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if ($programsError): ?>
        <div class="ta-alert ta-alert-error mb-6 flex-col items-start">
            <p class="font-medium">An error occurred loading programs.</p>
            <p class="text-sm mt-1"><?= e($programsError) ?></p>
        </div>
    <?php elseif (!$tableOk): ?>
        <div class="ta-alert ta-alert-warning mb-6 flex-col items-start">
            <p class="font-semibold">Programs tables are not installed yet.</p>
            <p class="text-sm mt-2">Run the SQL migration <code class="bg-amber-100 dark:bg-amber-900/30 px-1 rounded font-mono">database/migrations/039_programs_domain.sql</code> on your database, then reload this page.</p>
        </div>
    <?php elseif ($tableOk): ?>
        <?php
        $categoryFilterOptions = ['all' => 'All categories'];
        foreach ($programCategories as $c) {
            $categoryFilterOptions[(string) $c['id']] = $c['name'];
        }
        $filterBarAction = $adminBase . '/index.php';
        $filterBarHiddenFields = [['name' => 'page', 'value' => 'programs']];
        $filterBarFields = [
            ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'value' => $statusFilter, 'width' => 'w-40', 'options' => [
                'all' => 'All', 'draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled', 'archived' => 'Archived',
            ]],
            ['name' => 'category', 'type' => 'select', 'label' => 'Category', 'value' => $categoryFilter, 'width' => 'w-48', 'options' => $categoryFilterOptions],
            ['name' => 'search', 'type' => 'search', 'label' => 'Search', 'value' => $searchPrograms, 'placeholder' => 'Search title or description…', 'width' => 'w-64'],
        ];
        require __DIR__ . '/components/filter-bar.php';
        ?>

        <?php if (empty($programs)): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-16 text-center shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
            <h3 class="text-lg font-semibold text-gray-700 mb-2 dark:text-gray-200"><?= $hasListFilters ? 'No programs match your filters' : 'No programs yet' ?></h3>
            <p class="text-gray-500 text-sm mb-6 dark:text-gray-400"><?= $hasListFilters ? 'Try adjusting search or filters.' : 'Create your first program to offer classes or halaqahs to members.' ?></p>
            <?php if ($hasListFilters): ?>
            <a href="<?= e($adminBase . '/index.php?page=programs') ?>" class="page-header-btn-secondary inline-flex">Clear filters</a>
            <?php else: ?>
            <?php if ($can('programs.manage')): ?>
            <a href="<?= e($adminBase . '/index.php?page=program-edit') ?>" class="page-header-btn-primary inline-flex">Create your first program</a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <!-- Card view -->
        <div x-show="viewMode === 'card'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
            <?php foreach ($programs as $p):
                $statusColors = [
                    'published' => 'ta-badge-success',
                    'draft' => 'bg-gray-100 text-gray-600',
                    'cancelled' => 'ta-badge-error',
                    'archived' => 'bg-amber-100 text-amber-800',
                ];
                $statusClass = $statusColors[$p['status']] ?? 'bg-gray-100 text-gray-600';
                $bannerPath = $p['banner_image'] ?? null;
                ?>
            <div class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm transition-all duration-300 hover:border-brand-200 dark:border-gray-800 dark:bg-white/[0.03]">
                <?php if (!empty($bannerPath)): ?>
                <div class="mb-4 -mx-6 -mt-6 rounded-t-xl overflow-hidden h-40 bg-gray-100 dark:bg-gray-800">
                    <img src="<?= e($basePath . '/public/api/image.php?path=' . urlencode($bannerPath)) ?>" alt="" class="w-full h-40 object-cover object-top" loading="lazy">
                </div>
                <?php else: ?>
                <div class="mb-4 -mx-6 -mt-6 rounded-t-xl h-32 bg-gradient-to-r from-brand-500 to-purple-600 flex items-center justify-center">
                    <svg class="w-10 h-10 text-white/80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253"></path></svg>
                </div>
                <?php endif; ?>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $statusClass ?>"><?= e(ucfirst($p['status'])) ?></span>
                    <?php if (!empty($p['category_name'])): ?>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-brand-50 text-brand-700"><?= e($p['category_name']) ?></span>
                    <?php endif; ?>
                </div>
                <h3 class="font-bold text-gray-900 text-lg leading-snug dark:text-white"><?= e($p['title']) ?></h3>
                <?php if (!empty($p['description'])): ?>
                <p class="text-sm text-gray-500 mt-2 line-clamp-2 dark:text-gray-400"><?= e(mb_substr(strip_tags($p['description']), 0, 140)) ?><?= mb_strlen(strip_tags($p['description'])) > 140 ? '…' : '' ?></p>
                <?php endif; ?>
                <div class="mt-4 pt-4 border-t border-gray-100 flex items-center justify-between text-sm text-gray-600 dark:text-gray-300 dark:border-gray-800">
                    <span><?= $p['pricing_type'] === 'free' ? 'Free' : e(ucfirst(str_replace('_', ' ', $p['pricing_type']))) ?><?php if (!empty($p['price_amount']) && $p['pricing_type'] !== 'free'): ?> · $<?= e(number_format((float) $p['price_amount'], 2)) ?><?php endif; ?></span>
                    <span class="text-gray-500 dark:text-gray-400"><?= e(ucfirst($p['recurrence_type'] ?? 'none')) ?></span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="<?= e($adminBase . '/index.php?page=program-details&id=' . (int) $p['id']) ?>" class="flex-1 min-w-[5rem] text-center px-4 py-2 rounded-xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700">Details</a>
                    <?php if ($canMaintainPrograms): ?>
                    <a href="<?= e($adminBase . '/index.php?page=program-edit&id=' . (int) $p['id']) ?>" class="flex-1 min-w-[5rem] text-center px-4 py-2 rounded-xl border border-gray-200 text-gray-700 text-sm font-semibold hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700">Edit</a>
                    <?php endif; ?>
                    <a href="<?= e($adminBase . '/index.php?page=program-attendance&program_id=' . (int) $p['id']) ?>" class="flex-1 min-w-[5rem] text-center px-4 py-2 rounded-xl border border-gray-200 text-gray-700 text-sm font-semibold hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700">Attendance</a>
                    <?php if ($canMaintainPrograms): ?>
                    <button type="button" data-program-id="<?= (int) $p['id'] ?>" data-program-title="<?= e($p['title'] ?? 'Program') ?>" @click="deleteProgram(parseInt($event.currentTarget.getAttribute('data-program-id'), 10), $event.currentTarget.getAttribute('data-program-title'))" class="px-4 py-2 rounded-xl border border-rose-200 text-rose-700 text-sm font-semibold hover:bg-rose-50 dark:border-rose-900/40 dark:text-rose-300 dark:hover:bg-rose-950/30">Delete</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Table view -->
        <?php
        $programStatusVariants = ['published' => 'success', 'draft' => 'gray', 'cancelled' => 'error', 'archived' => 'warning'];
        $tableColumns = [
            ['key' => 'title', 'label' => 'Title', 'type' => 'raw', 'raw_key' => 'title_html'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'badge_variant_key' => 'status_variant'],
            ['key' => 'pricing', 'label' => 'Pricing', 'type' => 'text'],
            ['key' => 'recurrence', 'label' => 'Recurrence', 'type' => 'text'],
            ['key' => 'actions', 'label' => 'Actions', 'type' => 'actions', 'actions_key' => 'actions_html', 'class' => 'text-right'],
        ];
        $tableRows = [];
        foreach ($programs as $p) {
            $titleHtml = '<div class="font-semibold text-gray-900 dark:text-white/90">' . e($p['title']) . '</div>';
            if (!empty($p['description'])) {
                $titleHtml .= '<div class="mt-0.5 max-w-xs truncate text-theme-xs text-gray-500 dark:text-gray-400">' . e(mb_substr(strip_tags($p['description']), 0, 80)) . '</div>';
            }
            $pricing = $p['pricing_type'] === 'free'
                ? 'Free'
                : ucfirst(str_replace('_', ' ', $p['pricing_type'])) . (!empty($p['price_amount']) && $p['pricing_type'] !== 'free' ? ' · $' . number_format((float) $p['price_amount'], 2) : '');
            $pid = (int) $p['id'];
            $actionsHtml = '<div class="text-right whitespace-nowrap">'
                . '<a href="' . e($adminBase . '/index.php?page=program-details&id=' . $pid) . '" class="mr-3 text-theme-sm font-medium text-brand-600 hover:text-brand-700">Details</a>'
                . ($canMaintainPrograms ? '<a href="' . e($adminBase . '/index.php?page=program-edit&id=' . $pid) . '" class="mr-3 text-theme-sm font-medium text-brand-600 hover:text-brand-700">Edit</a>' : '')
                . '<a href="' . e($adminBase . '/index.php?page=program-attendance&program_id=' . $pid) . '" class="mr-3 text-theme-sm font-medium text-gray-600 hover:text-gray-900 dark:text-white">Attendance</a>'
                . ($canMaintainPrograms ? '<button type="button" data-program-id="' . $pid . '" data-program-title="' . e($p['title'] ?? 'Program') . '" onclick="headcountDeleteProgram(parseInt(this.getAttribute(\'data-program-id\'), 10), this.getAttribute(\'data-program-title\'))" class="text-theme-sm font-medium text-rose-600 hover:text-rose-800">Delete</button>' : '')
                . '</div>';
            $tableRows[] = [
                'title_html' => $titleHtml,
                'status' => ucfirst($p['status']),
                'status_variant' => $programStatusVariants[$p['status']] ?? 'gray',
                'pricing' => $pricing,
                'recurrence' => ucfirst($p['recurrence_type'] ?? 'none'),
                'actions_html' => $actionsHtml,
            ];
        }
        ?>
        <div x-show="viewMode === 'table'" class="mb-12">
            <?php require __DIR__ . '/components/data-table.php'; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Program categories modal (not related to event categories in Settings) -->
    <div x-show="catModalOpen" x-transition.opacity class="fixed inset-0 z-[80] flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="catModalOpen = false"></div>
        <div class="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between dark:border-gray-800">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Program categories</h2>
                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Used to group programs on the public site and in admin lists.</p>
                </div>
                <button type="button" @click="catModalOpen = false" class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">✕</button>
            </div>
            <div class="p-6 overflow-y-auto flex-1 space-y-6">
                <form @submit.prevent="addCategory()" class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_5.5rem_auto] gap-x-3 gap-y-2 items-end">
                    <div class="min-w-0">
                        <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">New category name</label>
                        <input type="text" x-model="newCatName" class="ta-input w-full" placeholder="e.g. Youth, Sisters, Classes" maxlength="120">
                    </div>
                    <div class="w-full sm:w-[5.5rem]">
                        <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Sort</label>
                        <input type="number" x-model.number="newCatSort" class="ta-input w-full" min="0" step="1" title="Sort order">
                    </div>
                    <button type="submit" class="page-header-btn-primary justify-center w-full sm:w-auto shrink-0" :disabled="catSaving || !newCatName.trim()">Add</button>
                </form>
                <div x-show="catLoading" class="text-sm text-gray-500 py-4 dark:text-gray-400">Loading…</div>
                <div x-show="!catLoading && categories.length === 0" class="text-sm text-gray-500 py-2 dark:text-gray-400">No categories yet. Add one above.</div>
                <ul class="divide-y divide-gray-100 border border-gray-100 rounded-xl overflow-hidden dark:border-gray-800 dark:divide-gray-800" x-show="!catLoading && categories.length">
                    <template x-for="c in categories" :key="c.id">
                        <li class="p-4 bg-white flex flex-col sm:flex-row sm:items-center gap-3 dark:bg-gray-800">
                            <div class="flex-1 min-w-0 flex flex-col sm:flex-row gap-2 sm:gap-3">
                                <input type="text" x-model="c.name" class="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm dark:border-gray-700" maxlength="120">
                                <input type="number" x-model.number="c.sort_order" class="w-full sm:w-20 border border-gray-200 rounded-xl px-3 py-2 text-sm dark:border-gray-700" min="0" step="1" title="Sort order">
                            </div>
                            <div class="flex gap-2 shrink-0">
                                <button type="button" @click="saveCategoryRow(c)" class="px-3 py-2 text-sm font-medium text-brand-600 border border-brand-100 rounded-xl hover:bg-brand-50" :disabled="catSaving">Save</button>
                                <button type="button" @click="deleteCategoryRow(c)" class="px-3 py-2 text-sm font-medium text-rose-600 border border-rose-100 rounded-xl hover:bg-rose-50" :disabled="catSaving">Delete</button>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
const PROGRAMS_API_URL = <?= json_encode($apiPrograms) ?>;
const PROGRAMS_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

async function headcountDeleteProgram(programId, programTitle, redirectUrl) {
    const title = (programTitle || 'this program').trim();
    const confirmed = typeof confirmAction === 'function'
        ? await confirmAction({
            title: 'Delete "' + title + '"?',
            message: 'The program will be archived and removed from the portal. Registrations and history are kept. You can find it later with Status → Archived.',
            type: 'danger',
            okText: 'Delete',
            cancelText: 'Cancel',
        })
        : window.confirm('Delete "' + title + '"?\n\nThe program will be archived and removed from the portal. Registrations and history are kept. You can find it later with Status → Archived.');
    if (!confirmed) {
        return;
    }
    try {
        const r = await fetch(PROGRAMS_API_URL + '?action=delete', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': PROGRAMS_CSRF_TOKEN,
            },
            body: JSON.stringify({ action: 'delete', id: programId, csrf_token: PROGRAMS_CSRF_TOKEN }),
        });
        const j = await r.json();
        if (j.success) {
            if (redirectUrl) {
                window.location.href = redirectUrl;
            } else {
                window.location.reload();
            }
            return;
        }
        window.alert(j.message || 'Could not delete program');
    } catch (e) {
        console.error(e);
        window.alert('An error occurred while deleting the program.');
    }
}

function programsPageApp() {
    return {
        viewMode: 'card',
        catModalOpen: false,
        catLoading: false,
        catSaving: false,
        categories: [],
        newCatName: '',
        newCatSort: 0,
        api: <?= json_encode($apiPrograms) ?>,
        csrf: <?= json_encode($csrfToken) ?>,
        init() {
            const saved = localStorage.getItem('programsViewMode');
            if (saved === 'card' || saved === 'table') {
                this.viewMode = saved;
            }
        },
        saveViewPreference(mode) {
            localStorage.setItem('programsViewMode', mode);
        },
        deleteProgram(programId, programTitle) {
            return headcountDeleteProgram(programId, programTitle);
        },
        openCatModal() {
            this.catModalOpen = true;
            this.loadCategories();
        },
        async loadCategories() {
            this.catLoading = true;
            try {
                const r = await fetch(this.api + '?action=categories', { credentials: 'same-origin' });
                const j = await r.json();
                this.categories = (j.success && Array.isArray(j.categories)) ? j.categories.map(c => ({
                    ...c,
                    sort_order: c.sort_order != null ? parseInt(c.sort_order, 10) : 0
                })) : [];
            } catch (e) {
                console.error(e);
                this.categories = [];
            }
            this.catLoading = false;
        },
        async addCategory() {
            const name = (this.newCatName || '').trim();
            if (!name) return;
            this.catSaving = true;
            try {
                const r = await fetch(this.api, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                    body: JSON.stringify({
                        action: 'save_category',
                        csrf_token: this.csrf,
                        name,
                        sort_order: parseInt(this.newCatSort, 10) || 0
                    })
                });
                const j = await r.json();
                if (j.success) {
                    this.newCatName = '';
                    this.newCatSort = 0;
                    await this.loadCategories();
                } else if (typeof alert === 'function') alert(j.message || 'Could not add category');
            } catch (e) {
                console.error(e);
            }
            this.catSaving = false;
        },
        async saveCategoryRow(c) {
            if (!c || !c.id) return;
            this.catSaving = true;
            try {
                const r = await fetch(this.api, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                    body: JSON.stringify({
                        action: 'save_category',
                        csrf_token: this.csrf,
                        id: c.id,
                        name: (c.name || '').trim(),
                        sort_order: parseInt(c.sort_order, 10) || 0
                    })
                });
                const j = await r.json();
                if (!j.success && typeof alert === 'function') alert(j.message || 'Could not save');
                await this.loadCategories();
            } catch (e) {
                console.error(e);
            }
            this.catSaving = false;
        },
        async deleteCategoryRow(c) {
            if (!c || !c.id) return;
            const ok = typeof confirmAction === 'function'
                ? await confirmAction({ title: 'Delete category', message: 'Delete “' + (c.name || '') + '”? Programs using it must be reassigned first.', type: 'danger', okText: 'Delete', cancelText: 'Cancel' })
                : confirm('Delete this category?');
            if (!ok) return;
            this.catSaving = true;
            try {
                const r = await fetch(this.api, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                    body: JSON.stringify({ action: 'delete_category', csrf_token: this.csrf, id: c.id })
                });
                const j = await r.json();
                if (!j.success && typeof alert === 'function') alert(j.message || 'Could not delete');
                await this.loadCategories();
            } catch (e) {
                console.error(e);
            }
            this.catSaving = false;
        }
    };
}
window.programsPageApp = programsPageApp;
window.headcountDeleteProgram = headcountDeleteProgram;
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

