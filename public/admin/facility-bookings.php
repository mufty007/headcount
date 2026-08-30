<?php
/**
 * Facility bookings queue (admin)
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
    <a href="<?= e($navUrls['facility-bookings-calendar'] ?? (rtrim($adminBase, '/') . '/?page=facility-bookings-calendar')) ?><?= $facilityFilter ? '&facility_id=' . (int) $facilityFilter : '' ?>" class="page-header-btn-secondary">Calendar</a>
    <a href="<?= e(rtrim($adminBase, '/') . '/?page=facilities') ?>" class="page-header-btn-secondary">Facilities</a>
    <?php
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if (!$tableOk): ?>
        <div class="ta-alert ta-alert-warning">Run migration 059_facilities_domain.sql first.</div>
    <?php else: ?>
    <div class="mb-6 flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $st => $label): ?>
        <a href="?page=facility-bookings&status=<?= e($st) ?><?= $facilityFilter ? '&facility_id=' . $facilityFilter : '' ?>"
           class="rounded-lg px-4 py-2 text-theme-sm font-semibold transition-colors <?= $statusFilter === $st ? 'bg-brand-600 text-white shadow-theme-xs' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05]' ?> dark:bg-gray-800"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <p class="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">Paid requests with an authorized card hold must be approved within about 7 days or the authorization expires and the booking is cancelled automatically.</p>

    <?php if (empty($bookings)): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-gray-500 dark:text-gray-400">No bookings in this view.</p>
        </div>
    <?php else:
        $bookingStatusVariants = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'error', 'cancelled' => 'gray'];
        $tableColumns = [
            ['key' => 'when', 'label' => 'When', 'type' => 'raw', 'raw_key' => 'when_html', 'class' => 'w-36'],
            ['key' => 'booking', 'label' => 'Booking', 'type' => 'raw', 'raw_key' => 'booking_html'],
            ['key' => 'requester', 'label' => 'Requester', 'type' => 'raw', 'raw_key' => 'requester_html'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'badge_variant_key' => 'status_variant'],
            ['key' => 'actions', 'label' => 'Actions', 'type' => 'actions', 'actions_key' => 'actions_html', 'class' => 'text-right'],
        ];
        $tableRows = [];
        foreach ($bookings as $b) {
            $canManageThis = !$isCoordinator || ($facSvc && $facSvc->userCanManageFacility($userId, $organizationId, (int) ($b['facility_id'] ?? 0), $userRole));
            $startTs = strtotime($b['start_datetime']);
            $endTs = strtotime($b['end_datetime']);
            $whenHtml = '<div class="flex shrink-0 flex-col text-center">'
                . '<span class="text-theme-xs text-gray-500 dark:text-gray-400">' . e(date('M j, Y', $startTs)) . '</span>'
                . '<span class="text-theme-sm font-medium text-gray-700 dark:text-gray-300">' . e(date('g:i A', $startTs)) . '</span>'
                . '<span class="text-theme-xs text-gray-400">' . e(date('g:i A', $endTs)) . ' end</span>'
                . '</div>';
            $bookingHtml = '<div class="font-semibold text-gray-900 dark:text-white/90">' . e($b['title']) . '</div>'
                . '<div class="mt-1 text-theme-sm text-gray-600 dark:text-gray-400">' . e($b['facility_name']) . '</div>';
            if (!empty($b['purpose'])) {
                $bookingHtml .= '<p class="mt-1 line-clamp-2 text-theme-xs text-gray-500 dark:text-gray-400">' . e($b['purpose']) . '</p>';
            }
            $setupLabel = headcount_facility_waiver_setup_label($b['waiver_setup_location'] ?? null, $b['waiver_setup_other'] ?? null);
            if ($setupLabel !== '') {
                $bookingHtml .= '<p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Setup: ' . e($setupLabel) . '</p>';
            }
            if (!empty($b['waiver_accepted_at'])) {
                $bookingHtml .= '<p class="mt-1 text-theme-xs font-medium text-success-600 dark:text-success-400">Waiver signed ' . e(date('M j, Y', strtotime($b['waiver_accepted_at']))) . '</p>';
            }
            if (!empty($b['total_amount']) && (float) $b['total_amount'] > 0) {
                $bookingHtml .= '<p class="mt-1 text-theme-sm font-semibold text-brand-700">$' . number_format((float) $b['total_amount'], 2) . '</p>';
            }
            $requesterHtml = '<div class="text-theme-sm font-medium text-gray-800 dark:text-white/90">' . e(trim($b['first_name'] . ' ' . $b['last_name'])) . '</div>'
                . '<div class="text-theme-xs text-gray-500 dark:text-gray-400">' . e($b['email']) . '</div>'
                . '<div class="text-theme-xs text-gray-400">via ' . e($b['booked_via']) . '</div>';
            $payBadge = [
                'awaiting_checkout' => ['Awaiting payment', 'gray'],
                'authorized' => ['Payment authorized', 'brand'],
                'captured' => ['Payment captured', 'success'],
                'released' => ['Hold released', 'gray'],
                'failed' => ['Payment failed', 'error'],
            ][$b['payment_status'] ?? ''] ?? null;
            $actionsHtml = '<div class="flex flex-wrap items-center justify-end gap-2">';
            if ($payBadge) {
                $pv = $payBadge[1];
                $badgeClsMap = [
                    'success' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
                    'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400',
                    'error' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400',
                    'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
                    'gray' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
                ];
                $payBadgeCls = $badgeClsMap[$pv] ?? $badgeClsMap['gray'];
                $actionsHtml .= '<span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium ' . $payBadgeCls . '">' . e($payBadge[0]) . '</span>';
            }
            if ($b['status'] === 'pending' && $canManageThis) {
                $actionsHtml .= '<button type="button" @click="approve(' . (int) $b['id'] . ')" class="rounded-lg bg-success-600 px-3 py-1.5 text-theme-sm font-semibold text-white hover:bg-success-700">Approve</button>';
                $actionsHtml .= '<button type="button" @click="reject(' . (int) $b['id'] . ')" class="rounded-lg bg-error-600 px-3 py-1.5 text-theme-sm font-semibold text-white hover:bg-error-700">Reject</button>';
            } elseif (in_array($b['status'], ['pending', 'approved'], true) && $canManageThis) {
                $actionsHtml .= '<button type="button" @click="cancel(' . (int) $b['id'] . ')" class="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-sm font-semibold text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700">Cancel</button>';
            }
            $actionsHtml .= '<a href="' . e(rtrim($adminBase, '/') . '/?page=facility-booking-waiver&id=' . (int) $b['id']) . '" class="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-sm font-semibold text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700">View waiver</a>';
            $actionsHtml .= '</div>';
            $tableRows[] = [
                'when_html' => $whenHtml,
                'booking_html' => $bookingHtml,
                'requester_html' => $requesterHtml,
                'status' => ucfirst($b['status']),
                'status_variant' => $bookingStatusVariants[$b['status']] ?? 'gray',
                'actions_html' => $actionsHtml,
            ];
        }
        require __DIR__ . '/components/data-table.php';
    endif; ?>
    <p x-show="msg" x-text="msg" class="mt-4 text-sm text-brand-600"></p>
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
