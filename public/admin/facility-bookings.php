<?php
/**
 * Facility bookings queue (admin)
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
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
$userData = $db->queryOne("SELECT first_name, last_name, email, role FROM users WHERE id = :id", ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

$statusFilter = get('status', 'pending');
$facilityFilter = (int) get('facility_id', 0);

$userRole = $user['role'] ?? 'admin';
$isCoordinator = ($userRole === 'coordinator');

$bookings = [];
$facilities = [];
$tableOk = false;
$facSvc = null;
try {
    $facSvc = new FacilityService();
    $tableOk = $facSvc->tableExists();
    if ($tableOk) {
        $facilities = $facSvc->listForOrg($organizationId, ['status' => 'active']);
        if ($isCoordinator) {
            $managedIds = $facSvc->getManagedFacilityIds($userId, $organizationId);
            $facilities = array_values(array_filter($facilities, static fn ($f) => in_array((int) ($f['id'] ?? 0), $managedIds, true)));
        }
        $bookSvc = new FacilityBookingService();
        $filters = [];
        if ($statusFilter !== 'all') {
            $filters['status'] = $statusFilter;
        }
        if ($facilityFilter > 0) {
            $filters['facility_id'] = $facilityFilter;
        }
        if ($isCoordinator) {
            $managedIds = $facSvc->getManagedFacilityIds($userId, $organizationId);
            if ($managedIds === []) {
                $filters['facility_ids'] = [0];
            } elseif (empty($filters['facility_id'])) {
                $filters['facility_ids'] = $managedIds;
            } elseif (!in_array((int) $filters['facility_id'], $managedIds, true)) {
                $filters['facility_ids'] = [0];
            }
        }
        $bookings = $bookSvc->listForOrg($organizationId, $filters);
    }
} catch (\Exception $e) {
    $bookingsError = $e->getMessage();
}

require_once __DIR__ . '/includes/layout-vars.php';
$apiUrl = $basePath . '/public/api/facility-bookings.php';
$csrfToken = CsrfMiddleware::getToken();
$pageTitle = 'Facility Bookings';
$currentPage = 'facility-bookings';
require __DIR__ . '/includes/header.php';

function formatBookingRange($start, $end) {
    return date('M j, Y g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end));
}
?>

<div class="animate-fade-in" x-data="facilityBookingsApp()" x-init="init()">
    <?php
    $pageHeaderTitle = 'Facility Bookings';
    $pageHeaderSubtitle = 'Review pending requests and manage confirmed bookings.';
    ob_start(); ?>
    <a href="<?= e(rtrim($adminBase, '/') . '/?page=facilities') ?>" class="page-header-btn-secondary">Facilities</a>
    <?php
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if (!$tableOk): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-900">Run migration 059_facilities_domain.sql first.</div>
    <?php else: ?>
    <div class="flex flex-wrap gap-3 mb-6">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $st => $label): ?>
        <a href="?page=facility-bookings&status=<?= e($st) ?><?= $facilityFilter ? '&facility_id=' . $facilityFilter : '' ?>"
           class="px-4 py-2 rounded-lg text-sm font-semibold <?= $statusFilter === $st ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-500 mb-4">Paid requests with an authorized card hold must be approved within about 7 days or the authorization expires and the booking is cancelled automatically.</p>

    <?php if (empty($bookings)): ?>
        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-10 text-center text-gray-500">No bookings in this view.</div>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($bookings as $b):
            $canManageThis = !$isCoordinator || ($facSvc && $facSvc->userCanManageFacility($userId, $organizationId, (int) ($b['facility_id'] ?? 0), $userRole));
        ?>
        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-5">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white"><?= e($b['title']) ?></h3>
                    <?php if (!empty($b['purpose'])): ?>
                    <p class="text-sm text-gray-600 mt-2 line-clamp-3"><?= e($b['purpose']) ?></p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-600 mt-1"><?= e($b['facility_name']) ?> · <?= e(formatBookingRange($b['start_datetime'], $b['end_datetime'])) ?></p>
                    <p class="text-sm text-gray-500 mt-1"><?= e(trim($b['first_name'] . ' ' . $b['last_name'])) ?> · <?= e($b['email']) ?> · via <?= e($b['booked_via']) ?></p>
                    <?php if (!empty($b['total_amount']) && (float) $b['total_amount'] > 0): ?>
                    <p class="text-sm font-semibold text-indigo-700 mt-1">$<?= number_format((float) $b['total_amount'], 2) ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0 flex-wrap justify-end">
                    <?php
                    $payBadge = [
                        'awaiting_checkout' => ['Awaiting payment', 'bg-gray-100 text-gray-600'],
                        'authorized' => ['Payment authorized', 'bg-sky-100 text-sky-800'],
                        'captured' => ['Payment captured', 'bg-emerald-100 text-emerald-800'],
                        'released' => ['Hold released', 'bg-slate-100 text-slate-600'],
                        'failed' => ['Payment failed', 'bg-red-100 text-red-700'],
                    ][$b['payment_status'] ?? ''] ?? null;
                    if ($payBadge):
                    ?>
                    <span class="text-xs font-bold px-2 py-1 rounded-full <?= $payBadge[1] ?>"><?= e($payBadge[0]) ?></span>
                    <?php endif; ?>
                    <?php
                    $statusClass = [
                        'pending' => 'bg-amber-100 text-amber-800',
                        'approved' => 'bg-green-100 text-green-800',
                        'rejected' => 'bg-red-100 text-red-800',
                        'cancelled' => 'bg-gray-100 text-gray-600',
                    ][$b['status']] ?? 'bg-gray-100';
                    ?>
                    <span class="text-xs font-bold px-2 py-1 rounded-full <?= $statusClass ?>"><?= e(ucfirst($b['status'])) ?></span>
                    <?php if ($b['status'] === 'pending' && $canManageThis): ?>
                    <button type="button" @click="approve(<?= (int) $b['id'] ?>)" class="px-3 py-1.5 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700">Approve</button>
                    <button type="button" @click="reject(<?= (int) $b['id'] ?>)" class="px-3 py-1.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700">Reject</button>
                    <?php elseif (in_array($b['status'], ['pending', 'approved'], true) && $canManageThis): ?>
                    <button type="button" @click="cancel(<?= (int) $b['id'] ?>)" class="px-3 py-1.5 bg-gray-200 text-gray-800 text-sm font-semibold rounded-lg hover:bg-gray-300">Cancel</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p x-show="msg" x-text="msg" class="mt-4 text-sm text-indigo-600"></p>
    <?php endif; ?>
</div>

<script>
function facilityBookingsApp() {
    return {
        apiUrl: <?= json_encode($apiUrl) ?>,
        csrf: <?= json_encode($csrfToken) ?>,
        msg: '',
        async post(action, extra = {}) {
            const res = await fetch(this.apiUrl + '?action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action, csrf_token: this.csrf, ...extra }),
            });
            return res.json();
        },
        init() {},
        async approve(id) {
            const data = await this.post('approve', { id });
            if (data.success) location.reload();
            else alert(data.message || 'Failed');
        },
        async reject(id) {
            const reason = prompt('Rejection reason (optional):') || '';
            const data = await this.post('reject', { id, reason });
            if (data.success) location.reload();
            else alert(data.message || 'Failed');
        },
        async cancel(id) {
            if (!confirm('Cancel this booking?')) return;
            const data = await this.post('cancel', { id });
            if (data.success) location.reload();
            else alert(data.message || 'Failed');
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
