<?php
/**
 * Org-wide Main Calendar: events, program sessions, and facility bookings.
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

AuthMiddleware::requireAdminOrCoordinator();

require_once __DIR__ . '/includes/layout-vars.php';

$organizationId = AuthMiddleware::getOrganizationId();
$db = Database::getInstance();
$userId = AuthMiddleware::getUserId();
$userData = $db->queryOne('SELECT first_name, last_name, email, role FROM users WHERE id = :id', ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

$adminRouter = rtrim($adminBase, '/') . '/';
$pageConfig = [
    'apiUrl' => $basePath . '/public/api/main-calendar.php',
    'eventDetailsBase' => $adminRouter . '?page=event-details&id=',
    'programDetailsBase' => $adminRouter . '?page=program-details&id=',
    'programAttendanceBase' => $adminRouter . '?page=program-attendance&program_id=',
    'facilityDetailsBase' => $adminRouter . '?page=facility-details&id=',
    'facilityBookingsBase' => $adminRouter . '?page=facility-bookings',
];

$pageTitle = 'Main Calendar';
$currentPage = 'main-calendar';
$adminMainFullWidth = true;
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in w-full" x-data="mainCalendarPage(<?= htmlspecialchars(json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)" x-init="init()">
    <?php
    $pageHeaderTitle = 'Main Calendar';
    $pageHeaderSubtitle = 'Events, program sessions, and facility bookings in one place.';
    require __DIR__ . '/components/page-header.php';
    ?>

    <div x-show="toast" x-cloak class="fixed bottom-6 right-6 z-[10000] rounded-xl px-4 py-3 text-sm font-semibold shadow-lg"
         :class="toastError ? 'bg-error-600 text-white' : 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'" x-text="toast"></div>

    <div class="mb-4 flex flex-wrap gap-3 text-xs text-gray-600 dark:text-gray-400">
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-emerald-600"></span> Event</span>
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-violet-600"></span> Program</span>
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-blue-600"></span> Facility booking</span>
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-amber-600"></span> Pending booking</span>
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-slate-400"></span> Internal block</span>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div id="main-calendar-el" class="min-h-[600px]"></div>
    </div>

    <div x-show="panelOpen" x-cloak
         x-transition:enter="transition-opacity ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[9998] flex justify-end" @keydown.escape.window="closePanel()">
        <div class="absolute inset-0 bg-black/40" @click="closePanel()"></div>
        <aside class="relative z-10 flex h-full w-full max-w-md flex-col bg-white shadow-xl dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white" x-text="panelTitle()"></h3>
                <button type="button" @click="closePanel()" class="text-gray-500 hover:text-gray-800 dark:hover:text-white text-2xl leading-none">&times;</button>
            </div>
            <div class="flex-1 overflow-y-auto p-5 space-y-4" x-show="panelItem">
                <h4 class="text-xl font-bold text-gray-900 dark:text-white" x-text="panelItem?.title"></h4>
                <p class="text-sm capitalize text-gray-500" x-text="panelItem?.status || panelItem?.item_type"></p>
                <template x-if="panelItem?.location">
                    <div><span class="text-xs font-semibold uppercase text-gray-400">Location</span><p class="text-sm" x-text="panelItem.location"></p></div>
                </template>
                <template x-if="panelItem?.facility_name">
                    <div><span class="text-xs font-semibold uppercase text-gray-400">Facility</span><p class="text-sm" x-text="panelItem.facility_name"></p></div>
                </template>
                <a :href="detailUrl()" class="block text-center rounded-lg bg-brand-600 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Open details</a>
            </div>
        </aside>
    </div>
</div>

<style>
.hc-admin-calendar-root .fc { --fc-border-color: rgb(229 231 235); --fc-page-bg-color: transparent; }
.dark .hc-admin-calendar-root .fc { --fc-border-color: rgb(55 65 81); --fc-neutral-bg-color: rgb(17 24 39); color: rgb(229 231 235); }
</style>
<script src="<?= e($basePath) ?>/public/js/admin-fullcalendar.js?v=20260827a"></script>
<script src="<?= e($basePath) ?>/public/js/main-calendar.js?v=20260827a"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
