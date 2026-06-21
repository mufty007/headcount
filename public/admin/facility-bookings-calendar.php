<?php
/**
 * Org-wide facility bookings calendar.
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
use Headcount\Services\FacilityService;

AuthMiddleware::requireAdminOrCoordinator();

require_once __DIR__ . '/includes/layout-vars.php';

$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$db = Database::getInstance();
$userData = $db->queryOne('SELECT first_name, last_name, email, role FROM users WHERE id = :id', ['id' => $userId]);
$userRole = $userData['role'] ?? 'admin';
$isCoordinator = ($userRole === 'coordinator');
$isAdmin = ($userRole === 'admin');

$facilityFilter = (int) get('facility_id', 0);
$facilities = [];
$tableOk = false;
$facSvc = new FacilityService();
try {
    $tableOk = $facSvc->tableExists();
    if ($tableOk) {
        $facilities = $facSvc->listForOrg($organizationId, ['status' => 'active']);
        if ($isCoordinator) {
            $managedIds = $facSvc->getManagedFacilityIds($userId, $organizationId);
            $facilities = array_values(array_filter(
                $facilities,
                static fn ($f) => in_array((int) ($f['id'] ?? 0), $managedIds, true)
            ));
        }
    }
} catch (\Exception $e) {
    $facilities = [];
}

$adminRouter = rtrim($adminBase, '/') . '/';
$apiBookings = $basePath . '/public/api/facility-bookings.php';
$csrfToken = CsrfMiddleware::getToken();

$pageConfig = [
    'apiBookings' => $apiBookings,
    'csrfToken' => $csrfToken,
    'isAdmin' => $isAdmin,
    'facilityFilter' => $facilityFilter > 0 ? $facilityFilter : '',
    'facilityDetailsBase' => $adminRouter . '?page=facility-details&id=',
    'eventEditBase' => $adminRouter . '?page=event-edit&id=',
    'queueUrl' => $adminRouter . '?page=facility-bookings',
];

$pageTitle = 'Facility Bookings Calendar';
$currentPage = 'facility-bookings-calendar';
$adminMainFullWidth = true;
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in w-full" x-data="facilityBookingsCalendarPage(<?= htmlspecialchars(json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)" x-init="init()">
    <?php
    $pageHeaderBreadcrumb = [
        ['label' => 'Facilities', 'url' => $navUrls['facilities'] ?? ($adminRouter . '?page=facilities')],
        ['label' => 'Bookings calendar'],
    ];
    $pageHeaderTitle = 'Facility Bookings Calendar';
    $pageHeaderSubtitle = 'All facilities — bookings, internal blocks, and linked Headcount events.';
    ob_start(); ?>
    <a href="<?= e($navUrls['facility-bookings'] ?? ($adminRouter . '?page=facility-bookings')) ?>" class="page-header-btn-secondary">Booking queue</a>
    <a href="<?= e($navUrls['facilities'] ?? ($adminRouter . '?page=facilities')) ?>" class="page-header-btn-secondary">All facilities</a>
    <?php
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if (!$tableOk): ?>
        <div class="ta-alert ta-alert-warning">Run migration 059_facilities_domain.sql first.</div>
    <?php else: ?>

    <div x-show="toast" x-cloak class="fixed bottom-6 right-6 z-[10000] rounded-xl px-4 py-3 text-sm font-semibold shadow-lg"
         :class="toastError ? 'bg-error-600 text-white' : 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'" x-text="toast"></div>

    <div class="mb-6 flex flex-wrap items-center gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
            Facility
            <select class="ml-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                    @change="setFacility($event.target.value)">
                <option value="" <?= $facilityFilter <= 0 ? 'selected' : '' ?>>All facilities</option>
                <?php foreach ($facilities as $f): ?>
                <option value="<?= (int) $f['id'] ?>" <?= $facilityFilter === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['name'] ?? 'Facility') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="flex flex-wrap gap-3 text-xs text-gray-600 dark:text-gray-400">
            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-blue-600"></span> Approved booking</span>
            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-amber-600"></span> Pending</span>
            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded border border-slate-300" style="background:#e2e8f0"></span> Internal block</span>
            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-violet-600"></span> Headcount event</span>
        </div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div id="facility-bookings-calendar-el" class="min-h-[600px]"></div>
    </div>

    <div x-show="panelOpen" x-cloak
         x-transition:enter="transition-opacity ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[9998] flex justify-end" @keydown.escape.window="closePanel()">
        <div class="absolute inset-0 bg-black/40" @click="closePanel()"></div>
        <aside x-show="panelOpen"
               x-transition:enter="transform transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transform transition ease-in duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
               class="relative z-10 flex h-full w-full max-w-md flex-col bg-white shadow-xl dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Booking details</h3>
                <button type="button" @click="closePanel()" class="text-2xl leading-none text-gray-500 hover:text-gray-800 dark:hover:text-white">&times;</button>
            </div>
            <div class="flex-1 overflow-y-auto p-5 space-y-4" x-show="panelItem">
                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">Facility</p>
                    <p class="font-semibold text-gray-900 dark:text-white" x-text="panelItem?.facility_name"></p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">Type</p>
                    <p class="text-sm" x-text="typeLabel(panelItem?.type)"></p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">Title</p>
                    <p class="font-medium" x-text="panelItem?.display_title || panelItem?.title"></p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-gray-400">When</p>
                    <p class="text-sm" x-text="formatWhen(panelItem)"></p>
                </div>
                <template x-if="panelItem?.status && panelItem?.type && String(panelItem.type).startsWith('booking')">
                    <div>
                        <p class="text-xs font-semibold uppercase text-gray-400">Status</p>
                        <p class="capitalize text-sm" x-text="panelItem.status"></p>
                    </div>
                </template>
                <div class="flex flex-col gap-2 pt-2">
                    <a :href="facilityDetailsUrl(panelItem?.facility_id) + '&tab=calendar'" class="text-center rounded-lg border border-gray-200 py-2.5 text-sm font-semibold hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">Open facility calendar</a>
                    <template x-if="panelItem?.type === 'headcount_event' && panelItem?.source_id">
                        <a :href="eventEditUrl(panelItem.source_id)" class="text-center rounded-lg bg-brand-600 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Edit Headcount event</a>
                    </template>
                    <template x-if="panelItem?.type === 'booking_pending'">
                        <div class="flex gap-2">
                            <button type="button" @click="approveBooking()" class="flex-1 rounded-lg bg-success-600 py-2 text-sm font-semibold text-white">Approve</button>
                            <button type="button" @click="rejectBooking()" class="flex-1 rounded-lg bg-error-600 py-2 text-sm font-semibold text-white">Reject</button>
                        </div>
                    </template>
                    <a :href="queueUrl()" class="text-center text-sm text-brand-600 hover:underline">View booking queue</a>
                </div>
            </div>
        </aside>
    </div>

    <?php endif; ?>
</div>

<style>
.hc-admin-calendar-root .fc { --fc-border-color: rgb(229 231 235); --fc-page-bg-color: transparent; }
.dark .hc-admin-calendar-root .fc { --fc-border-color: rgb(55 65 81); --fc-neutral-bg-color: rgb(17 24 39); color: rgb(229 231 235); }
</style>
<script src="<?= e($basePath) ?>/public/js/admin-fullcalendar.js?v=20260620b"></script>
<script src="<?= e($basePath) ?>/public/js/facility-bookings-calendar.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
