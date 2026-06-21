<?php
/**
 * Admin events calendar view.
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

$statusFilter = get('status', 'all');
$apiEvents = $basePath . '/public/api/events.php';
$adminRouter = rtrim($adminBase, '/') . '/';
$eventsCalendarConfig = [
    'apiEvents' => $apiEvents,
    'statusFilter' => $statusFilter,
    'adminBase' => $adminRouter,
    'eventDetailsBase' => $adminRouter . '?page=event-details&id=',
    'eventEditBase' => $adminRouter . '?page=event-edit&id=',
    'checkinBase' => $adminRouter . '?page=checkin&event_id=',
    'createEventUrl' => $adminRouter . '?page=event-create',
];

$pageTitle = 'Events Calendar';
$currentPage = 'events-calendar';
$adminMainFullWidth = true;
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in w-full" x-data="eventsCalendarPage(<?= htmlspecialchars(json_encode($eventsCalendarConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)" x-init="init()">
    <?php
    $pageHeaderBreadcrumb = [
        ['label' => 'Events', 'url' => $navUrls['events'] ?? ($adminRouter . '?page=events')],
        ['label' => 'Calendar'],
    ];
    $pageHeaderTitle = 'Events Calendar';
    $pageHeaderSubtitle = 'Month, week, and day views of all organization events.';
    ob_start(); ?>
    <a href="<?= e($navUrls['events'] ?? ($adminRouter . '?page=events')) ?>" class="page-header-btn-secondary">List view</a>
    <a href="<?= e($navUrls['event-create'] ?? ($adminRouter . '?page=event-create')) ?>" class="page-header-btn-primary">Create event</a>
    <?php
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <div x-show="toast" x-cloak class="fixed bottom-6 right-6 z-[10000] rounded-xl px-4 py-3 text-sm font-semibold shadow-lg"
         :class="toastError ? 'bg-error-600 text-white' : 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'" x-text="toast"></div>

    <div class="mb-6 flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Draft', 'scheduled' => 'Scheduled'] as $st => $label): ?>
        <button type="button" @click="setStatus('<?= e($st) ?>')"
                class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
                :class="statusFilter === '<?= e($st) ?>' ? 'bg-brand-600 text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05]'"><?= e($label) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="mb-3 flex flex-wrap gap-3 text-xs text-gray-600 dark:text-gray-400">
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-emerald-600"></span> Published</span>
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-gray-500"></span> Draft</span>
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-amber-600"></span> Scheduled</span>
        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-red-600"></span> Cancelled</span>
    </div>
    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Click an event for details. Click a date to create a new event on that day.</p>

    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div id="events-calendar-el" class="min-h-[600px]"></div>
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
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Event</h3>
                <button type="button" @click="closePanel()" class="text-gray-500 hover:text-gray-800 dark:hover:text-white text-2xl leading-none">&times;</button>
            </div>
            <div class="flex-1 overflow-y-auto p-5 space-y-4" x-show="panelEvent">
                <div>
                    <h4 class="text-xl font-bold text-gray-900 dark:text-white" x-text="panelEvent?.title"></h4>
                    <p class="mt-1 text-sm capitalize text-gray-500" x-text="panelEvent?.status"></p>
                </div>
                <div><span class="text-xs font-semibold uppercase text-gray-400">When</span><p class="text-sm" x-text="formatEventWhen(panelEvent)"></p></div>
                <template x-if="panelEvent?.location">
                    <div><span class="text-xs font-semibold uppercase text-gray-400">Location</span><p class="text-sm" x-text="panelEvent.location"></p></div>
                </template>
                <template x-if="panelEvent?.facility_name">
                    <div><span class="text-xs font-semibold uppercase text-gray-400">Facility</span><p class="text-sm" x-text="panelEvent.facility_name"></p></div>
                </template>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><span class="block text-xs text-gray-500">RSVP yes (heads)</span><span class="text-lg font-bold" x-text="panelEvent?.rsvp_yes_heads ?? 0"></span></div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><span class="block text-xs text-gray-500">Checked in</span><span class="text-lg font-bold" x-text="panelEvent?.checked_in ?? 0"></span></div>
                </div>
                <div class="flex flex-col gap-2 pt-2">
                    <a :href="detailsUrl(panelEvent?.id)" class="text-center rounded-lg border border-gray-200 py-2.5 text-sm font-semibold hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">View details</a>
                    <a :href="editUrl(panelEvent?.id)" class="text-center rounded-lg bg-brand-600 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Edit event</a>
                    <a :href="checkinUrl(panelEvent?.id)" class="text-center rounded-lg bg-success-600 py-2.5 text-sm font-semibold text-white hover:bg-success-700">Start check-in</a>
                </div>
            </div>
        </aside>
    </div>
</div>

<style>
.hc-admin-calendar-root .fc { --fc-border-color: rgb(229 231 235); --fc-page-bg-color: transparent; }
.dark .hc-admin-calendar-root .fc { --fc-border-color: rgb(55 65 81); --fc-neutral-bg-color: rgb(17 24 39); color: rgb(229 231 235); }
</style>
<script src="<?= e($basePath) ?>/public/js/admin-fullcalendar.js?v=20260620b"></script>
<script src="<?= e($basePath) ?>/public/js/events-calendar.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
