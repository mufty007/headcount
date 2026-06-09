<?php
/**
 * Single facility hub: overview, schedule blocks, booking history.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
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
$scheduleBlocks = $svc->getBlockedTimesInRange($facility, $rangeStart, $rangeEnd);

$linkedEvents = $svc->listLinkedEventsForFacility($facilityId, $organizationId, 80);
headcount_decode_html_entities_in_event_rows($linkedEvents);

$facilityManagers = $svc->managersTableExists() ? $svc->getManagers($facilityId, $organizationId) : [];

$manualBlocks = is_array($facility['blocked_times'] ?? null) ? $facility['blocked_times'] : [];

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

$reservationSlots = ['bookings' => [], 'events' => [], 'manual' => []];
foreach ($scheduleBlocks as $block) {
    $id = (string) ($block['id'] ?? '');
    if (str_starts_with($id, 'event-')) {
        $reservationSlots['events'][] = $block;
    } elseif (str_starts_with($id, 'blocked-')) {
        $reservationSlots['manual'][] = $block;
    } else {
        $reservationSlots['bookings'][] = $block;
    }
}
$reservationTotal = count($scheduleBlocks);
$publishedLinkedCount = 0;
foreach ($linkedEvents as $ev) {
    if (strtolower(trim((string) ($ev['status'] ?? ''))) === 'published') {
        $publishedLinkedCount++;
    }
}

$pageTitle = $facility['name'] ?? 'Facility';
$currentPage = 'facility-details';
$adminMainFullWidth = true;
require __DIR__ . '/includes/header.php';

function facilityDetailsFormatRange($start, $end): string
{
    return date('M j, Y g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end));
}

function facilityDetailsPlainText($value): string
{
    return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

$editUrl = rtrim($adminBase, '/') . '/?page=facility-edit&id=' . $facilityId;
$bookingsUrl = rtrim($adminBase, '/') . '/?page=facility-bookings&facility_id=' . $facilityId;
?>

<div class="animate-fade-in w-full">
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

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <?php if (!empty($facilityManagers)): ?>
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] lg:col-span-2">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Facility managers</h2>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">These staff receive booking request emails and can approve or reject requests for this facility.</p>
            <ul class="flex flex-wrap gap-2">
                <?php foreach ($facilityManagers as $mgr): ?>
                <li class="inline-flex items-center gap-2 rounded-full bg-brand-50 dark:bg-brand-950/40 px-3 py-1.5 text-sm text-brand-900 dark:text-brand-200">
                    <span class="font-semibold"><?= e(trim($mgr['first_name'] . ' ' . $mgr['last_name'])) ?></span>
                    <span class="text-brand-600/70 text-xs"><?= e($mgr['email']) ?> · <?= e($mgr['role']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Schedule blocks</h2>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">Manual blocks (from facility settings) and published IMCA events linked to this facility.</p>

            <?php if (!empty($manualBlocks)): ?>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Manual blocked times</h3>
            <ul class="space-y-2 mb-6 text-sm">
                <?php foreach ($manualBlocks as $block): ?>
                <li class="flex flex-wrap gap-2 items-baseline border-b border-gray-100 dark:border-gray-800 pb-2">
                    <span class="font-semibold text-gray-900 dark:text-white"><?= e($block['date'] ?? '') ?></span>
                    <span class="text-gray-600 dark:text-gray-300"><?= e(($block['start_time'] ?? '') . ' – ' . ($block['end_time'] ?? '')) ?></span>
                    <?php if (!empty($block['reason'])): ?><span class="text-gray-500 dark:text-gray-400">· <?= e($block['reason']) ?></span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if (!empty($linkedEvents)): ?>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Linked events</h3>
            <ul class="space-y-2 mb-4 text-sm max-h-64 overflow-y-auto">
                <?php foreach ($linkedEvents as $ev):
                    $st = strtolower(trim((string) ($ev['status'] ?? '')));
                    $blocksBookings = ($st === 'published');
                    $evEdit = rtrim($adminBase, '/') . '/?page=event-edit&id=' . (int) $ev['id'];
                ?>
                <li class="border-b border-gray-100 dark:border-gray-800 pb-2">
                    <a href="<?= e($evEdit) ?>" class="font-semibold text-brand-600 hover:underline"><?= e(facilityDetailsPlainText($ev['title'] ?? 'Event')) ?></a>
                    <span class="text-gray-600 dark:text-gray-300"> · <?= e(substr((string) ($ev['event_date'] ?? ''), 0, 10)) ?>
                    <?php if (!empty($ev['start_time']) && !empty($ev['end_time'])): ?>
                        <?= e(substr((string) $ev['start_time'], 0, 5)) ?>–<?= e(substr((string) $ev['end_time'], 0, 5)) ?>
                    <?php endif; ?>
                    </span>
                    <span class="ml-1 text-xs font-semibold px-2 py-0.5 rounded-full <?= $blocksBookings ? 'bg-violet-100 text-violet-800' : 'bg-gray-100 text-gray-600' ?> dark:bg-gray-800 dark:text-gray-300">
                        <?= $blocksBookings ? 'Blocks bookings' : e(ucfirst($st)) ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php elseif (empty($manualBlocks)): ?>
            <p class="text-sm text-gray-500 dark:text-gray-400">No manual blocks or linked events. Link a facility on an event (when published) or add blocked times in Edit facility.</p>
            <?php endif; ?>

            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                Active reservations (next 120 days)
                <span class="font-normal text-gray-500 dark:text-gray-400">— <?= (int) $reservationTotal ?> slot<?= $reservationTotal === 1 ? '' : 's' ?>
                (<?= count($reservationSlots['bookings']) ?> booking<?= count($reservationSlots['bookings']) === 1 ? '' : 's' ?>,
                <?= count($reservationSlots['events']) ?> IMCA event<?= count($reservationSlots['events']) === 1 ? '' : 's' ?>,
                <?= count($reservationSlots['manual']) ?> manual)</span>
            </h3>
            <?php if ($reservationTotal === 0): ?>
            <p class="text-sm text-gray-500 dark:text-gray-400">No upcoming bookings or blocks in the next 120 days.</p>
            <?php else: ?>
            <ul class="space-y-2 text-sm max-h-64 overflow-y-auto">
                <?php
                $reservationTypeLabels = [
                    'bookings' => ['label' => 'Booking', 'class' => 'bg-sky-100 text-sky-800'],
                    'events' => ['label' => 'IMCA event', 'class' => 'bg-violet-100 text-violet-800'],
                    'manual' => ['label' => 'Manual block', 'class' => 'bg-gray-100 text-gray-700'],
                ];
                foreach (['bookings', 'events', 'manual'] as $slotType):
                    foreach ($reservationSlots[$slotType] as $block):
                        $badge = $reservationTypeLabels[$slotType];
                        $statusLabel = !empty($block['status']) && $block['status'] !== 'blocked'
                            ? ucfirst((string) $block['status'])
                            : '';
                ?>
                <li class="text-gray-700 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800 pb-2 flex flex-wrap items-baseline gap-2">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span>
                    <span class="font-medium"><?= e(facilityDetailsPlainText($block['title'] ?? 'Reserved')) ?></span>
                    <?php if ($statusLabel !== ''): ?>
                    <span class="text-xs text-gray-500 dark:text-gray-400"><?= e($statusLabel) ?></span>
                    <?php endif; ?>
                    <span class="text-gray-500 text-xs w-full dark:text-gray-400"><?= e(facilityDetailsFormatRange($block['start_datetime'], $block['end_datetime'])) ?></span>
                </li>
                <?php
                    endforeach;
                endforeach;
                ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Quick actions</h2>
            <div class="space-y-3">
                <a href="<?= e($editUrl) ?>" class="block w-full text-center px-4 py-3 rounded-xl bg-brand-600 text-white font-semibold hover:bg-brand-700">Edit facility settings</a>
                <a href="<?= e($editUrl) ?>#blocked-times" class="block w-full text-center px-4 py-3 rounded-xl border border-gray-200 text-gray-800 font-semibold hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-800">Manage manual blocked times</a>
                <a href="<?= e($bookingsUrl) ?>&status=pending" class="block w-full text-center px-4 py-3 rounded-xl border border-gray-200 text-gray-800 font-semibold hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:bg-gray-800">Review pending bookings</a>
            </div>
            <?php if (!empty($facility['slug'])): ?>
            <p class="text-xs text-gray-500 mt-6 dark:text-gray-400">Portal slug: <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded"><?= e($facility['slug']) ?></code></p>
            <?php endif; ?>
        </section>
    </div>

    <?php
    $bookingStatusVariants = [
        'pending' => 'warning', 'approved' => 'success', 'rejected' => 'error', 'cancelled' => 'gray',
    ];
    $bookingRows = [];
    foreach ($bookings as $b) {
        $st = strtolower(trim((string) ($b['status'] ?? '')));
        $requesterName = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
        $bookingRows[] = [
            'title' => facilityDetailsPlainText($b['title'] ?? ''),
            'when' => facilityDetailsFormatRange($b['start_datetime'], $b['end_datetime']),
            'member_name' => $requesterName,
            'member_email' => $b['email'] ?? '',
            'status' => ucfirst($st),
            'status_variant' => $bookingStatusVariants[$st] ?? 'gray',
            'payment' => $b['payment_status'] ?? '—',
        ];
    }
    $tableTitle = 'Booking history';
    $tableActions = '';
    ob_start();
    foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $st => $label): ?>
        <a href="?page=facility-details&id=<?= $facilityId ?>&booking_status=<?= e($st) ?>"
           class="btn-secondary py-2 text-theme-sm shadow-theme-xs <?= $bookingStatus === $st ? '!bg-brand-600 !text-white !border-brand-600' : '' ?>"><?= e($label) ?></a>
    <?php endforeach;
    $tableActions = ob_get_clean();
    $tableColumns = [
        ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
        ['key' => 'when', 'label' => 'When', 'type' => 'text'],
        ['key' => 'member_name', 'label' => 'Requester', 'type' => 'avatar', 'avatar_name' => 'member_name', 'avatar_email' => 'member_email'],
        ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'badge_variant_key' => 'status_variant'],
        ['key' => 'payment', 'label' => 'Payment', 'type' => 'text'],
    ];
    $tableRows = $bookingRows;
    $tableEmptyMessage = 'No bookings for this filter.';
    require __DIR__ . '/components/data-table.php';
    unset($tableTitle, $tableActions, $tableColumns, $tableRows, $tableEmptyMessage, $bookingRows);
    ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
