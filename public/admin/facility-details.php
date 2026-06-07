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
        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</p>
            <p class="text-lg font-bold text-gray-900 dark:text-white mt-1"><?= e(ucfirst($facility['status'] ?? 'inactive')) ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Rate</p>
            <p class="text-lg font-bold text-indigo-700 mt-1">
                <?php if (!empty($facility['is_paid']) && (float) ($facility['hourly_rate'] ?? 0) > 0): ?>
                    $<?= number_format((float) $facility['hourly_rate'], 2) ?> / hr
                <?php else: ?>
                    Free
                <?php endif; ?>
            </p>
        </div>
        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Pending bookings</p>
            <p class="text-2xl font-bold text-amber-700 mt-1"><?= (int) $bookingCountByStatus['pending'] ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Approved bookings</p>
            <p class="text-2xl font-bold text-green-700 mt-1"><?= (int) $bookingCountByStatus['approved'] ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">IMCA events (published)</p>
            <p class="text-2xl font-bold text-violet-700 mt-1"><?= (int) $publishedLinkedCount ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-5">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Manual blocks</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= count($manualBlocks) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <?php if (!empty($facilityManagers)): ?>
        <section class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-6 lg:col-span-2">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Facility managers</h2>
            <p class="text-sm text-gray-500 mb-4">These staff receive booking request emails and can approve or reject requests for this facility.</p>
            <ul class="flex flex-wrap gap-2">
                <?php foreach ($facilityManagers as $mgr): ?>
                <li class="inline-flex items-center gap-2 rounded-full bg-indigo-50 dark:bg-indigo-950/40 px-3 py-1.5 text-sm text-indigo-900 dark:text-indigo-200">
                    <span class="font-semibold"><?= e(trim($mgr['first_name'] . ' ' . $mgr['last_name'])) ?></span>
                    <span class="text-indigo-600/70 text-xs"><?= e($mgr['email']) ?> · <?= e($mgr['role']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
        <section class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-6">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Schedule blocks</h2>
            <p class="text-sm text-gray-500 mb-4">Manual blocks (from facility settings) and published IMCA events linked to this facility.</p>

            <?php if (!empty($manualBlocks)): ?>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Manual blocked times</h3>
            <ul class="space-y-2 mb-6 text-sm">
                <?php foreach ($manualBlocks as $block): ?>
                <li class="flex flex-wrap gap-2 items-baseline border-b border-gray-100 dark:border-slate-800 pb-2">
                    <span class="font-semibold text-gray-900 dark:text-white"><?= e($block['date'] ?? '') ?></span>
                    <span class="text-gray-600"><?= e(($block['start_time'] ?? '') . ' – ' . ($block['end_time'] ?? '')) ?></span>
                    <?php if (!empty($block['reason'])): ?><span class="text-gray-500">· <?= e($block['reason']) ?></span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if (!empty($linkedEvents)): ?>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Linked events</h3>
            <ul class="space-y-2 mb-4 text-sm max-h-64 overflow-y-auto">
                <?php foreach ($linkedEvents as $ev):
                    $st = strtolower(trim((string) ($ev['status'] ?? '')));
                    $blocksBookings = ($st === 'published');
                    $evEdit = rtrim($adminBase, '/') . '/?page=event-edit&id=' . (int) $ev['id'];
                ?>
                <li class="border-b border-gray-100 dark:border-slate-800 pb-2">
                    <a href="<?= e($evEdit) ?>" class="font-semibold text-indigo-600 hover:underline"><?= e(facilityDetailsPlainText($ev['title'] ?? 'Event')) ?></a>
                    <span class="text-gray-600"> · <?= e(substr((string) ($ev['event_date'] ?? ''), 0, 10)) ?>
                    <?php if (!empty($ev['start_time']) && !empty($ev['end_time'])): ?>
                        <?= e(substr((string) $ev['start_time'], 0, 5)) ?>–<?= e(substr((string) $ev['end_time'], 0, 5)) ?>
                    <?php endif; ?>
                    </span>
                    <span class="ml-1 text-xs font-semibold px-2 py-0.5 rounded-full <?= $blocksBookings ? 'bg-violet-100 text-violet-800' : 'bg-gray-100 text-gray-600' ?>">
                        <?= $blocksBookings ? 'Blocks bookings' : e(ucfirst($st)) ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php elseif (empty($manualBlocks)): ?>
            <p class="text-sm text-gray-500">No manual blocks or linked events. Link a facility on an event (when published) or add blocked times in Edit facility.</p>
            <?php endif; ?>

            <h3 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">
                Active reservations (next 120 days)
                <span class="font-normal text-gray-500">— <?= (int) $reservationTotal ?> slot<?= $reservationTotal === 1 ? '' : 's' ?>
                (<?= count($reservationSlots['bookings']) ?> booking<?= count($reservationSlots['bookings']) === 1 ? '' : 's' ?>,
                <?= count($reservationSlots['events']) ?> IMCA event<?= count($reservationSlots['events']) === 1 ? '' : 's' ?>,
                <?= count($reservationSlots['manual']) ?> manual)</span>
            </h3>
            <?php if ($reservationTotal === 0): ?>
            <p class="text-sm text-gray-500">No upcoming bookings or blocks in the next 120 days.</p>
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
                <li class="text-gray-700 dark:text-slate-300 border-b border-gray-100 dark:border-slate-800 pb-2 flex flex-wrap items-baseline gap-2">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span>
                    <span class="font-medium"><?= e(facilityDetailsPlainText($block['title'] ?? 'Reserved')) ?></span>
                    <?php if ($statusLabel !== ''): ?>
                    <span class="text-xs text-gray-500"><?= e($statusLabel) ?></span>
                    <?php endif; ?>
                    <span class="text-gray-500 text-xs w-full"><?= e(facilityDetailsFormatRange($block['start_datetime'], $block['end_datetime'])) ?></span>
                </li>
                <?php
                    endforeach;
                endforeach;
                ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-6">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Quick actions</h2>
            <div class="space-y-3">
                <a href="<?= e($editUrl) ?>" class="block w-full text-center px-4 py-3 rounded-xl bg-indigo-600 text-white font-semibold hover:bg-indigo-700">Edit facility settings</a>
                <a href="<?= e($editUrl) ?>#blocked-times" class="block w-full text-center px-4 py-3 rounded-xl border border-gray-200 text-gray-800 font-semibold hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Manage manual blocked times</a>
                <a href="<?= e($bookingsUrl) ?>&status=pending" class="block w-full text-center px-4 py-3 rounded-xl border border-gray-200 text-gray-800 font-semibold hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200">Review pending bookings</a>
            </div>
            <?php if (!empty($facility['slug'])): ?>
            <p class="text-xs text-gray-500 mt-6">Portal slug: <code class="bg-gray-100 dark:bg-slate-800 px-1 rounded"><?= e($facility['slug']) ?></code></p>
            <?php endif; ?>
        </section>
    </div>

    <section class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white">Booking history</h2>
            <div class="flex flex-wrap gap-2">
                <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $st => $label): ?>
                <a href="?page=facility-details&id=<?= $facilityId ?>&booking_status=<?= e($st) ?>"
                   class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $bookingStatus === $st ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($bookings)): ?>
            <p class="text-gray-500 text-sm">No bookings for this filter.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="ta-table w-full text-sm">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>When</th>
                        <th>Requester</th>
                        <th>Status</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td class="font-medium"><?= e(facilityDetailsPlainText($b['title'] ?? '')) ?></td>
                        <td><?= e(facilityDetailsFormatRange($b['start_datetime'], $b['end_datetime'])) ?></td>
                        <td><?= e(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?></td>
                        <td><span class="text-xs font-semibold uppercase"><?= e($b['status']) ?></span></td>
                        <td><?= e($b['payment_status'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
