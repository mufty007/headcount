<?php
/**
 * Single facility hub: overview, schedule blocks, booking history.
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
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityService;
use Headcount\Services\FacilityBookingService;

if (empty($_SESSION['user_id'])) {
    AuthMiddleware::requireAdminOrCoordinator();
}
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();

$db = Database::getInstance();
$userData = $db->queryOne('SELECT first_name, last_name, email, role FROM users WHERE id = :id', ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

require_once __DIR__ . '/includes/layout-vars.php';

$facilityId = (int) ($_GET['id'] ?? 0);
if ($facilityId <= 0) {
    Utilities::redirect(rtrim($adminBase, '/') . '/?page=facilities');
    exit;
}

$svc = new FacilityService();
$facility = $svc->getByIdForOrg($facilityId, $organizationId);
if (!$facility) {
    Utilities::redirect(rtrim($adminBase, '/') . '/?page=facilities');
    exit;
}

$bookSvc = new FacilityBookingService();
$bookingStatus = get('booking_status', 'all');
$bookingFilters = ['facility_id' => $facilityId];
if ($bookingStatus !== 'all') {
    $bookingFilters['status'] = $bookingStatus;
}
$bookings = $bookSvc->listForOrg($organizationId, $bookingFilters);

$rangeStart = date('Y-m-d');
$rangeEnd = date('Y-m-d', strtotime('+120 days'));
$scheduleBlocks = $bookSvc->getAvailabilityForAdmin($facilityId, $rangeStart, $rangeEnd, true);

$linkedEvents = $svc->listLinkedEventsForFacility($facilityId, $organizationId, 80);
headcount_decode_html_entities_in_event_rows($linkedEvents);

$facilityManagers = $svc->managersTableExists() ? $svc->getManagers($facilityId, $organizationId) : [];
$managerIds = array_map(static fn ($m) => (int) $m['id'], $facilityManagers);
$isAdmin = ($user['role'] ?? '') === 'admin';
$csrfToken = CsrfMiddleware::getToken();

$manualBlocks = is_array($facility['blocked_times'] ?? null) ? $facility['blocked_times'] : [];

$slotMinTime = '06:00:00';
$slotMaxTime = '22:00:00';
$hours = $facility['operating_hours'] ?? null;
if (is_array($hours)) {
    $opens = [];
    $closes = [];
    foreach ($hours as $day) {
        if (!is_array($day) || empty($day['open'])) {
            continue;
        }
        $o = substr((string) ($day['open'] ?? ''), 0, 5);
        $c = substr((string) ($day['close'] ?? ''), 0, 5);
        if ($o !== '') {
            $opens[] = $o;
        }
        if ($c !== '') {
            $closes[] = $c;
        }
    }
    if ($opens !== []) {
        sort($opens);
        $slotMinTime = $opens[0] . ':00';
    }
    if ($closes !== []) {
        rsort($closes);
        $slotMaxTime = $closes[0] . ':00';
    }
}

$allFacilityBookings = $bookSvc->listForOrg($organizationId, ['facility_id' => $facilityId]);
$bookingCountByStatus = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0,
];
foreach ($allFacilityBookings as $b) {
    $st = strtolower(trim((string) ($b['status'] ?? '')));
    if (isset($bookingCountByStatus[$st])) {
        $bookingCountByStatus[$st]++;
    }
}

$reservationTotal = count($scheduleBlocks);
$publishedLinkedCount = 0;
foreach ($linkedEvents as $ev) {
    if (strtolower(trim((string) ($ev['status'] ?? ''))) === 'published') {
        $publishedLinkedCount++;
    }
}

$apiBookings = $basePath . '/public/api/facility-bookings.php';
$apiFacilities = $basePath . '/public/api/facilities.php';
$eventEditBase = rtrim($adminBase, '/') . '/?page=event-edit&id=';
$eventDetailsBase = rtrim($adminBase, '/') . '/?page=event-details&id=';
$initialTab = preg_match('/^(calendar|bookings|blocks|managers)$/', (string) get('tab', 'calendar')) ? get('tab', 'calendar') : 'calendar';

if (!function_exists('facilityDetailsFormatRange')) {
    function facilityDetailsFormatRange($start, $end): string
    {
        return date('M j, Y g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end));
    }
}
if (!function_exists('facilityDetailsPlainText')) {
    function facilityDetailsPlainText($value): string
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

$bookingRowsJson = [];
foreach ($bookings as $b) {
    $st = strtolower(trim((string) ($b['status'] ?? '')));
    $bookingRowsJson[] = [
        'id' => (int) ($b['id'] ?? 0),
        'title' => facilityDetailsPlainText($b['title'] ?? ''),
        'when' => facilityDetailsFormatRange($b['start_datetime'], $b['end_datetime']),
        'requester' => trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')),
        'email' => $b['email'] ?? '',
        'status' => $st,
        'payment' => $b['payment_status'] ?? '—',
    ];
}

$pageTitle = $facility['name'] ?? 'Facility';
$currentPage = 'facility-details';
$adminMainFullWidth = true;
require __DIR__ . '/includes/header.php';

$editUrl = rtrim($adminBase, '/') . '/?page=facility-edit&id=' . $facilityId;
$bookingsUrl = rtrim($adminBase, '/') . '/?page=facility-bookings&facility_id=' . $facilityId;
$facilityDetailsConfig = [
    'facilityId' => $facilityId,
    'csrfToken' => $csrfToken,
    'isAdmin' => $isAdmin,
    'initialTab' => $initialTab,
    'editUrl' => $editUrl,
    'bookingsUrl' => $bookingsUrl,
    'eventEditBase' => $eventEditBase,
    'eventDetailsBase' => $eventDetailsBase,
    'apiBookings' => $apiBookings,
    'apiFacilities' => $apiFacilities,
    'slotMinTime' => $slotMinTime,
    'slotMaxTime' => $slotMaxTime,
    'scheduleBlocks' => $scheduleBlocks,
    'bookings' => $bookingRowsJson,
    'bookingStatus' => $bookingStatus,
    'managerIds' => $managerIds,
    'eligibleManagers' => [],
];
?>

<div class="animate-fade-in w-full" x-data="facilityDetailsPage(<?= htmlspecialchars(json_encode($facilityDetailsConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)" x-init="init()">
    <?php
    $pageHeaderBreadcrumb = [
        ['label' => 'Facilities', 'url' => rtrim($adminBase, '/') . '/?page=facilities'],
        ['label' => facilityDetailsPlainText($facility['name'] ?? 'Facility')],
    ];
    $pageHeaderTitle = e(facilityDetailsPlainText($facility['name'] ?? 'Facility'));
    $pageHeaderSubtitle = !empty($facility['location']) ? e(facilityDetailsPlainText($facility['location'])) : 'Facility overview and bookings';
    ob_start(); ?>
    <a href="<?= e(rtrim($adminBase, '/') . '/?page=facilities') ?>" class="page-header-btn-secondary">All facilities</a>
    <a href="<?= e($editUrl) ?>" class="page-header-btn-primary">Edit facility</a>
    <a href="<?= e($bookingsUrl) ?>" class="page-header-btn-secondary">Booking queue</a>
    <?php
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
        <?php
        $statLabel = 'Status';
        $statValue = ucfirst($facility['status'] ?? 'inactive');
        $statTrend = null;
        $statTrendLabel = 'Facility status';
        $statAccent = 'brand';
        $statIcon = 'layers';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Rate';
        $statValue = (!empty($facility['is_paid']) && (float) ($facility['hourly_rate'] ?? 0) > 0)
            ? '$' . number_format((float) $facility['hourly_rate'], 2) . ' / hr'
            : 'Free';
        $statTrend = null;
        $statTrendLabel = 'Hourly rate';
        $statAccent = 'success';
        $statIcon = 'currency';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Pending bookings';
        $statValue = (int) $bookingCountByStatus['pending'];
        $statTrend = null;
        $statTrendLabel = 'Awaiting review';
        $statAccent = 'warning';
        $statIcon = 'ticket';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Approved bookings';
        $statValue = (int) $bookingCountByStatus['approved'];
        $statTrend = null;
        $statTrendLabel = 'Confirmed reservations';
        $statAccent = 'success';
        $statIcon = 'calendar';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'IMCA events';
        $statValue = (int) $publishedLinkedCount;
        $statTrend = null;
        $statTrendLabel = 'Published & linked';
        $statAccent = 'sky';
        $statIcon = 'chart';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Manual blocks';
        $statValue = count($manualBlocks);
        $statTrend = null;
        $statTrendLabel = 'Blocked time slots';
        $statAccent = 'gray';
        $statIcon = 'layers';
        require __DIR__ . '/components/stat-card-trend.php';
        ?>
    </div>

    <div x-show="toast" x-cloak class="fixed bottom-6 right-6 z-[10000] rounded-xl px-4 py-3 text-sm font-semibold shadow-lg"
         :class="toastError ? 'bg-error-600 text-white' : 'bg-gray-900 text-white dark:bg-white dark:text-gray-900'" x-text="toast"></div>

    <nav role="tablist" aria-label="Facility sections" class="mb-6 flex flex-wrap gap-1 rounded-xl border border-gray-200 bg-gray-100 p-1 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <?php foreach (['calendar' => 'Calendar', 'bookings' => 'Booking history', 'blocks' => 'Schedule blocks', 'managers' => 'Managers'] as $tabKey => $tabLabel): ?>
        <button type="button" role="tab" @click="setTab('<?= e($tabKey) ?>')"
                :class="activeTab === '<?= e($tabKey) ?>' ? 'bg-white text-brand-600 shadow-sm ring-1 ring-brand-200 dark:bg-gray-700 dark:text-brand-300 dark:ring-brand-500/40' : 'text-gray-600 hover:bg-gray-200/60 dark:text-gray-300 dark:hover:bg-gray-700/80'"
                class="rounded-lg px-4 py-2 text-sm font-semibold transition-all"><?= e($tabLabel) ?></button>
        <?php endforeach; ?>
    </nav>

    <!-- Calendar tab -->
    <div x-show="activeTab === 'calendar'" x-cloak class="space-y-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-3 flex flex-wrap gap-3 text-xs text-gray-600 dark:text-gray-400">
                <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-blue-600"></span> Approved booking</span>
                <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-amber-600"></span> Pending</span>
                <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded border border-slate-300" style="background:#e2e8f0"></span> Internal block</span>
                <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-violet-600"></span> Headcount event</span>
            </div>
            <?php if ($isAdmin): ?>
            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">Click or drag on empty time to block the facility for internal use (maintenance, off-platform events).</p>
            <?php endif; ?>
            <div id="facility-calendar-el" class="min-h-[520px]"></div>
        </div>
    </div>

    <!-- Booking history tab -->
    <div x-show="activeTab === 'bookings'" x-cloak class="space-y-6">
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Quick actions</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <a href="<?= e($editUrl) ?>" class="text-center px-4 py-3 rounded-xl bg-brand-600 text-white font-semibold hover:bg-brand-700">Edit facility settings</a>
                <a href="<?= e($bookingsUrl) ?>&status=pending" class="text-center px-4 py-3 rounded-xl border border-gray-200 font-semibold hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800">Review pending queue</a>
                <button type="button" @click="setTab('blocks')" class="text-center px-4 py-3 rounded-xl border border-gray-200 font-semibold hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800">Add schedule block</button>
            </div>
        </section>
        <div class="mb-4 flex flex-wrap gap-2">
            <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $st => $label): ?>
            <a href="?page=facility-details&id=<?= $facilityId ?>&tab=bookings&booking_status=<?= e($st) ?>"
               class="rounded-lg px-4 py-2 text-sm font-semibold <?= $bookingStatus === $st ? 'bg-brand-600 text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <table class="min-w-full text-sm">
                <thead class="border-b border-gray-100 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">Title</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">When</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">Requester</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300">Payment</th>
                        <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="!bookings.length">
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">No bookings for this filter.</td></tr>
                    </template>
                    <template x-for="row in bookings" :key="row.id">
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white" x-text="row.title"></td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300" x-text="row.when"></td>
                            <td class="px-4 py-3">
                                <div class="font-medium" x-text="row.requester"></div>
                                <div class="text-xs text-gray-500" x-text="row.email"></div>
                            </td>
                            <td class="px-4 py-3 capitalize" x-text="row.status"></td>
                            <td class="px-4 py-3" x-text="row.payment"></td>
                            <td class="px-4 py-3 text-right">
                                <template x-if="row.status === 'pending' && isAdmin">
                                    <span class="inline-flex gap-2">
                                        <button type="button" @click="approveBooking(row.id)" class="rounded-lg bg-success-600 px-3 py-1 text-xs font-semibold text-white">Approve</button>
                                        <button type="button" @click="rejectBooking(row.id)" class="rounded-lg bg-error-600 px-3 py-1 text-xs font-semibold text-white">Reject</button>
                                    </span>
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Schedule blocks tab -->
    <div x-show="activeTab === 'blocks'" x-cloak class="space-y-6">
        <?php if ($isAdmin): ?>
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Add internal block</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block"><span class="text-sm font-medium text-gray-700 dark:text-gray-300">Date</span>
                    <input type="date" x-model="blockForm.date" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"></label>
                <label class="block"><span class="text-sm font-medium text-gray-700 dark:text-gray-300">Start</span>
                    <input type="time" x-model="blockForm.start_time" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"></label>
                <label class="block"><span class="text-sm font-medium text-gray-700 dark:text-gray-300">End</span>
                    <input type="time" x-model="blockForm.end_time" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"></label>
                <label class="block sm:col-span-2 lg:col-span-3"><span class="text-sm font-medium text-gray-700 dark:text-gray-300">Reason / title</span>
                    <input type="text" x-model="blockForm.reason" placeholder="Maintenance, board meeting…" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"></label>
            </div>
            <div class="mt-4 flex flex-wrap gap-4 text-sm">
                <label class="inline-flex items-center gap-2"><input type="checkbox" x-model="blockForm.block_member"> Block member bookings</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" x-model="blockForm.block_guest"> Block guest bookings</label>
            </div>
            <p x-show="blockMessage" class="mt-2 text-sm text-error-600" x-text="blockMessage"></p>
            <button type="button" @click="saveBlock(blockForm)" :disabled="blockSaving" class="mt-4 btn-primary">Save block</button>
        </section>
        <?php endif; ?>
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1">All reservations (next 120 days)</h2>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400"><?= (int) $reservationTotal ?> slot<?= $reservationTotal === 1 ? '' : 's' ?> including bookings, Headcount events, and manual blocks.</p>
            <ul class="space-y-3 text-sm max-h-[480px] overflow-y-auto">
                <template x-if="!scheduleBlocks.length">
                    <li class="text-gray-500 dark:text-gray-400">No upcoming bookings or blocks.</li>
                </template>
                <template x-for="(block, idx) in scheduleBlocks" :key="idx + '-' + (block.start_datetime || '')">
                    <li class="flex flex-wrap items-start gap-2 border-b border-gray-100 pb-3 dark:border-gray-800">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full" :class="typeBadgeClass(block.type)" x-text="typeLabel(block.type)"></span>
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 dark:text-white" x-text="block.title || 'Reserved'"></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400" x-text="formatRange(block.start_datetime, block.end_datetime)"></div>
                        </div>
                        <template x-if="block.editable && isAdmin">
                            <button type="button" @click="removeBlock(block.block_index)" class="text-xs font-semibold text-error-600 hover:underline">Remove</button>
                        </template>
                        <template x-if="block.type === 'headcount_event' && block.source_id">
                            <a :href="eventEditBase + block.source_id" class="text-xs font-semibold text-brand-600 hover:underline">Edit event</a>
                        </template>
                    </li>
                </template>
            </ul>
        </section>
    </div>

    <!-- Managers tab -->
    <div x-show="activeTab === 'managers'" x-cloak>
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Facility managers</h2>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">Assigned managers receive booking request emails and can approve or reject requests.</p>
            <?php if (!$isAdmin): ?>
            <ul class="flex flex-wrap gap-2">
                <?php foreach ($facilityManagers as $mgr): ?>
                <li class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1.5 text-sm dark:bg-brand-950/40">
                    <span class="font-semibold"><?= e(trim($mgr['first_name'] . ' ' . $mgr['last_name'])) ?></span>
                    <span class="text-xs text-gray-500"><?= e($mgr['email']) ?></span>
                </li>
                <?php endforeach; ?>
                <?php if (empty($facilityManagers)): ?><li class="text-sm text-gray-500">No managers assigned.</li><?php endif; ?>
            </ul>
            <?php else: ?>
            <div class="space-y-2 max-h-80 overflow-y-auto mb-4">
                <template x-if="!eligibleManagers.length">
                    <p class="text-sm text-gray-500">Loading eligible staff…</p>
                </template>
                <template x-for="em in eligibleManagers" :key="em.id">
                    <label class="flex items-center gap-3 rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                        <input type="checkbox" :checked="managerIds.includes(em.id)" @change="toggleManager(em.id)">
                        <span><span class="font-medium" x-text="em.first_name + ' ' + em.last_name"></span>
                        <span class="text-xs text-gray-500" x-text="' · ' + em.email"></span></span>
                    </label>
                </template>
            </div>
            <p x-show="managersMessage" class="text-sm text-error-600 mb-2" x-text="managersMessage"></p>
            <button type="button" @click="saveManagers()" :disabled="managersSaving" class="btn-primary">Save managers</button>
            <?php endif; ?>
        </section>
    </div>

    <!-- Side panel -->
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
                <h3 class="text-lg font-bold text-gray-900 dark:text-white" x-text="panelMode === 'block' ? 'Block for internal use' : 'Details'"></h3>
                <button type="button" @click="closePanel()" class="text-gray-500 hover:text-gray-800 dark:hover:text-white">&times;</button>
            </div>
            <div class="flex-1 overflow-y-auto p-5">
                <template x-if="panelMode === 'block'">
                    <div class="space-y-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Internal blocks reserve the facility for use outside Headcount events.</p>
                        <label class="block text-sm"><span class="font-medium">Date</span><input type="date" x-model="panelBlockForm.date" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-700 dark:bg-gray-800"></label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block text-sm"><span class="font-medium">Start</span><input type="time" x-model="panelBlockForm.start_time" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-700 dark:bg-gray-800"></label>
                            <label class="block text-sm"><span class="font-medium">End</span><input type="time" x-model="panelBlockForm.end_time" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-700 dark:bg-gray-800"></label>
                        </div>
                        <label class="block text-sm"><span class="font-medium">Reason</span><input type="text" x-model="panelBlockForm.reason" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-700 dark:bg-gray-800"></label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="panelBlockForm.block_member"> Block members</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="panelBlockForm.block_guest"> Block guests</label>
                        <button type="button" @click="submitPanelBlock()" :disabled="blockSaving" class="btn-primary w-full">Save block</button>
                    </div>
                </template>
                <template x-if="panelMode === 'view' && panelData">
                    <div class="space-y-4">
                        <div><span class="text-xs font-semibold uppercase text-gray-400">Type</span><p class="font-medium" x-text="typeLabel(panelData.type)"></p></div>
                        <div><span class="text-xs font-semibold uppercase text-gray-400">Title</span><p class="font-medium" x-text="panelData.title || 'Reserved'"></p></div>
                        <div><span class="text-xs font-semibold uppercase text-gray-400">When</span><p x-text="formatRange(panelData.start_datetime, panelData.end_datetime)"></p></div>
                        <template x-if="panelData.status && panelData.type && panelData.type.startsWith('booking')">
                            <div><span class="text-xs font-semibold uppercase text-gray-400">Status</span><p class="capitalize" x-text="panelData.status"></p></div>
                        </template>
                        <template x-if="panelData.type === 'booking_pending' && isAdmin">
                            <div class="flex gap-2 pt-2">
                                <button type="button" @click="approveBooking(panelData.source_id)" class="flex-1 rounded-lg bg-success-600 py-2 text-sm font-semibold text-white">Approve</button>
                                <button type="button" @click="rejectBooking(panelData.source_id)" class="flex-1 rounded-lg bg-error-600 py-2 text-sm font-semibold text-white">Reject</button>
                            </div>
                        </template>
                        <template x-if="panelData.editable && isAdmin">
                            <button type="button" @click="removeBlock(panelData.block_index)" class="w-full rounded-lg border border-error-200 py-2 text-sm font-semibold text-error-600">Remove block</button>
                        </template>
                        <template x-if="panelData.type === 'headcount_event' && panelData.source_id">
                            <a :href="eventEditBase + panelData.source_id" class="block text-center rounded-lg bg-brand-600 py-2 text-sm font-semibold text-white">Edit Headcount event</a>
                        </template>
                    </div>
                </template>
            </div>
        </aside>
    </div>
</div>

<style>
.hc-admin-calendar-root .fc { --fc-border-color: rgb(229 231 235); --fc-page-bg-color: transparent; }
.dark .hc-admin-calendar-root .fc { --fc-border-color: rgb(55 65 81); --fc-neutral-bg-color: rgb(17 24 39); color: rgb(229 231 235); }
</style>
<script src="<?= e($basePath) ?>/public/js/admin-fullcalendar.js?v=20260620b"></script>
<script src="<?= e($basePath) ?>/public/js/facility-details.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
