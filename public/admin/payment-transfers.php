<?php

/**
 * Payments — Stripe checkout collection summary, sync, refunds, and charts.
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
use Headcount\Services\FacilityService;

AuthMiddleware::requireAdminOrCoordinator();
AuthMiddleware::requireCan('payments.manage');

$organizationId = AuthMiddleware::getOrganizationId();
$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

// Get the current user for the header
$userId = AuthMiddleware::getUserId();
$userData = $db->queryOne("SELECT first_name, last_name, email, role FROM users WHERE id = :id", ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin'
] : [
    'name' => 'Administrator',
    'email' => 'admin@headcount.local',
    'role' => 'admin'
];

// Get filter parameters
$status = get('status', 'all');
$search = get('search', '');
$allowedStatus = ['all', 'unpaid', 'failed', 'collected'];
if (!in_array($status, $allowedStatus, true)) {
    $status = 'all';
}

$tab = get('tab', 'events');
if (!in_array($tab, ['events', 'facilities', 'reports'], true)) {
    $tab = 'events';
}

$facStatus = get('fac_status', 'all');
$allowedFacStatus = ['all', 'captured', 'authorized', 'awaiting', 'failed'];
if (!in_array($facStatus, $allowedFacStatus, true)) {
    $facStatus = 'all';
}
$facSearch = get('fac_search', '');

// Get all paid-ticket events with payment summaries (Stripe / cash rows on `payments`)
$sql = "SELECT 
            e.id,
            e.title,
            e.event_date,
            e.start_time,
            e.ticket_price,
            COUNT(DISTINCT p.id) as payment_count,
            COALESCE(SUM(CASE WHEN p.status = 'paid' THEN 1 ELSE 0 END), 0) as completed_payment_count,
            COALESCE(SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END), 0) as pending_checkout_count,
            COALESCE(SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END), 0) as pending_checkout_amount,
            COALESCE(SUM(CASE WHEN p.status = 'failed' THEN 1 ELSE 0 END), 0) as failed_payment_count,
            COALESCE(SUM(CASE WHEN p.status = 'failed' THEN p.amount ELSE 0 END), 0) as failed_amount,
            COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END), 0) as total_collected,
            MIN(p.created_at) as first_payment_date,
            MAX(p.created_at) as last_payment_date
        FROM events e
        LEFT JOIN payments p ON e.id = p.event_id
        WHERE e.organization_id = :org_id 
        AND e.ticket_price > 0";

$params = ['org_id' => $organizationId];

if ($status === 'unpaid') {
    $sql .= " AND EXISTS (
            SELECT 1 FROM payments p2 
            WHERE p2.event_id = e.id 
            AND p2.status = 'pending'
        )";
} elseif ($status === 'failed') {
    $sql .= " AND EXISTS (
            SELECT 1 FROM payments p2 
            WHERE p2.event_id = e.id 
            AND p2.status = 'failed'
        )";
} elseif ($status === 'collected') {
    $sql .= " AND EXISTS (
            SELECT 1 FROM payments p2 
            WHERE p2.event_id = e.id 
            AND p2.status = 'paid' 
            AND p2.amount > 0
        )";
}

if ($search) {
    $sql .= " AND (e.title LIKE :search1 OR e.location LIKE :search2)";
    $params['search1'] = "%$search%";
    $params['search2'] = "%$search%";
}

$sql .= " GROUP BY e.id
        HAVING payment_count > 0
        ORDER BY e.event_date DESC, e.title ASC";

$events = $db->query($sql, $params);
if (method_exists(Utilities::class, 'decodeHtmlEntitiesInEventRows')) {
    Utilities::decodeHtmlEntitiesInEventRows($events);
}

// Calculate totals
$totalCollected = 0;
$totalPendingCheckoutCount = 0;
$totalPendingCheckoutAmount = 0;
$totalFailedCount = 0;
$totalFailedAmount = 0;
foreach ($events as $event) {
    $totalCollected += (float) ($event['total_collected'] ?? 0);
    $totalPendingCheckoutCount += (int) ($event['pending_checkout_count'] ?? 0);
    $totalPendingCheckoutAmount += (float) ($event['pending_checkout_amount'] ?? 0);
    $totalFailedCount += (int) ($event['failed_payment_count'] ?? 0);
    $totalFailedAmount += (float) ($event['failed_amount'] ?? 0);
}

$paymentTransfersChartData = null;
if ($tab === 'reports') {
    // Chart data: org-wide status counts (paid-ticket events only)
    $statusRows = $db->query(
        "SELECT p.status, COUNT(*) AS cnt
         FROM payments p
         INNER JOIN events e ON e.id = p.event_id
         WHERE e.organization_id = :org_id AND e.ticket_price > 0
         GROUP BY p.status",
        ['org_id' => $organizationId]
    );
    $statusCounts = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'refunded' => 0];
    foreach ($statusRows as $row) {
        $k = strtolower((string) ($row['status'] ?? ''));
        if (isset($statusCounts[$k])) {
            $statusCounts[$k] = (int) ($row['cnt'] ?? 0);
        }
    }

    $trendRows = $db->query(
        "SELECT DATE(p.created_at) AS d, COALESCE(SUM(p.amount), 0) AS rev
         FROM payments p
         INNER JOIN events e ON e.id = p.event_id
         WHERE e.organization_id = :org_id AND e.ticket_price > 0
           AND p.status = 'paid'
           AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
         GROUP BY DATE(p.created_at)
         ORDER BY d ASC",
        ['org_id' => $organizationId]
    );
    $paidTrendLabels = [];
    $paidTrendAmounts = [];
    foreach ($trendRows as $row) {
        $paidTrendLabels[] = (string) ($row['d'] ?? '');
        $paidTrendAmounts[] = round((float) ($row['rev'] ?? 0), 2);
    }

    $eventsForBar = $events;
    usort($eventsForBar, static function ($a, $b) {
        return ((float) ($b['total_collected'] ?? 0)) <=> ((float) ($a['total_collected'] ?? 0));
    });
    $eventsForBar = array_slice($eventsForBar, 0, 10);
    $topEventCategories = [];
    $topEventAmounts = [];
    foreach ($eventsForBar as $ev) {
        $title = (string) ($ev['title'] ?? 'Event');
        if (function_exists('mb_substr')) {
            $short = mb_strlen($title) > 42 ? mb_substr($title, 0, 40) . '…' : $title;
        } else {
            $short = strlen($title) > 42 ? substr($title, 0, 40) . '…' : $title;
        }
        $topEventCategories[] = $short;
        $topEventAmounts[] = round((float) ($ev['total_collected'] ?? 0), 2);
    }

    $orgBranding = $db->queryOne('SELECT primary_color FROM organizations WHERE id = :id', ['id' => $organizationId]);
    $primaryColor = is_string($orgBranding['primary_color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/', $orgBranding['primary_color'])
        ? $orgBranding['primary_color']
        : '#3B82F6';

    $paymentTransfersChartData = [
        'primaryColor' => $primaryColor,
        'status' => [
            'labels' => ['Paid', 'Unpaid checkout', 'Failed', 'Refunded'],
            'series' => [
                $statusCounts['paid'],
                $statusCounts['pending'],
                $statusCounts['failed'],
                $statusCounts['refunded'],
            ],
        ],
        'topEvents' => [
            'categories' => $topEventCategories,
            'amounts' => $topEventAmounts,
        ],
        'paidTrend' => [
            'labels' => $paidTrendLabels,
            'amounts' => $paidTrendAmounts,
        ],
    ];
}

$facilityPaymentsEnabled = false;
$facilitiesWithPayments = [];
$totalFacCaptured = 0.0;
$totalFacAuthorized = 0.0;
$totalFacAuthorizedCount = 0;
$totalFacAwaitingCount = 0;
$totalFacFailedCount = 0;

if ($tab === 'facilities') {
    try {
        $facSvc = new FacilityService();
        $facilityPaymentsEnabled = $facSvc->tableExists('facility_bookings')
            && $facSvc->columnExistsPublic('facility_bookings', 'payment_status');
        if ($facilityPaymentsEnabled) {
            $facSql = "SELECT
                    f.id,
                    f.name,
                    f.location,
                    COUNT(b.id) AS booking_count,
                    COALESCE(SUM(CASE WHEN b.payment_status = 'captured' THEN 1 ELSE 0 END), 0) AS captured_count,
                    COALESCE(SUM(CASE WHEN b.payment_status = 'captured' THEN COALESCE(b.total_amount, 0) ELSE 0 END), 0) AS captured_amount,
                    COALESCE(SUM(CASE WHEN b.payment_status = 'authorized' THEN 1 ELSE 0 END), 0) AS authorized_count,
                    COALESCE(SUM(CASE WHEN b.payment_status = 'authorized' THEN COALESCE(b.total_amount, 0) ELSE 0 END), 0) AS authorized_amount,
                    COALESCE(SUM(CASE WHEN b.payment_status = 'awaiting_checkout' THEN 1 ELSE 0 END), 0) AS awaiting_count,
                    COALESCE(SUM(CASE WHEN b.payment_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count
                FROM facilities f
                INNER JOIN facility_bookings b ON b.facility_id = f.id
                WHERE f.organization_id = :org_id
                  AND b.payment_status != 'not_required'";
            $facParams = ['org_id' => $organizationId];

            if ($facStatus === 'captured') {
                $facSql .= " AND b.payment_status = 'captured'";
            } elseif ($facStatus === 'authorized') {
                $facSql .= " AND b.payment_status = 'authorized'";
            } elseif ($facStatus === 'awaiting') {
                $facSql .= " AND b.payment_status = 'awaiting_checkout'";
            } elseif ($facStatus === 'failed') {
                $facSql .= " AND b.payment_status = 'failed'";
            }

            if ($facSearch !== '') {
                $facSql .= " AND (f.name LIKE :fac_search1 OR f.location LIKE :fac_search2)";
                $facParams['fac_search1'] = '%' . $facSearch . '%';
                $facParams['fac_search2'] = '%' . $facSearch . '%';
            }

            $facSql .= " GROUP BY f.id, f.name, f.location
                ORDER BY captured_amount DESC, f.name ASC";

            $facilitiesWithPayments = $db->query($facSql, $facParams);
            foreach ($facilitiesWithPayments as $facRow) {
                $totalFacCaptured += (float) ($facRow['captured_amount'] ?? 0);
                $totalFacAuthorized += (float) ($facRow['authorized_amount'] ?? 0);
                $totalFacAuthorizedCount += (int) ($facRow['authorized_count'] ?? 0);
                $totalFacAwaitingCount += (int) ($facRow['awaiting_count'] ?? 0);
                $totalFacFailedCount += (int) ($facRow['failed_count'] ?? 0);
            }
        }
    } catch (\Throwable $e) {
        error_log('payment-transfers facilities tab: ' . $e->getMessage());
    }
}

// Calculate base path for assets (needed before chart JS URLs)
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}
$assetsBase = $basePath . '/public/assets/';

$paymentsTabEventsUrl = $adminBase . '/?' . http_build_query(array_merge(
    ['page' => 'payment-transfers', 'tab' => 'events'],
    $status !== 'all' ? ['status' => $status] : [],
    $search !== '' ? ['search' => $search] : []
));
$paymentsTabFacilitiesUrl = $adminBase . '/?' . http_build_query(array_merge(
    ['page' => 'payment-transfers', 'tab' => 'facilities'],
    $facStatus !== 'all' ? ['fac_status' => $facStatus] : [],
    $facSearch !== '' ? ['fac_search' => $facSearch] : []
));
$paymentsTabReportsUrl = $adminBase . '/?' . http_build_query(array_merge(
    ['page' => 'payment-transfers', 'tab' => 'reports'],
    $status !== 'all' ? ['status' => $status] : [],
    $search !== '' ? ['search' => $search] : []
));

if ($tab === 'reports') {
    $apexChartsLocalPath = (empty($basePath) || $basePath === '/') ? '/public/js/apexcharts.min.js' : rtrim($basePath, '/') . '/public/js/apexcharts.min.js';
    $apexChartsLocalPath = preg_replace('#/+#', '/', $apexChartsLocalPath);
    $paymentTransfersChartsJsPath = (empty($basePath) || $basePath === '/') ? '/public/js/payment-transfers-charts.js' : rtrim($basePath, '/') . '/public/js/payment-transfers-charts.js';
    $paymentTransfersChartsJsPath = preg_replace('#/+#', '/', $paymentTransfersChartsJsPath);
    if (!isset($additionalJS) || !is_array($additionalJS)) {
        $additionalJS = [];
    }
    $additionalJS[] = $apexChartsLocalPath;
    $additionalJS[] = $paymentTransfersChartsJsPath;
}

$pageTitle = 'Payments';
$currentPage = 'payment-transfers';
require __DIR__ . '/includes/header.php';
?>
<script>const API_BASE_URL_PAYMENT_TRANSFERS = <?= json_encode($basePath . '/public/api/payment-transfers.php') ?>;</script>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('paymentTransfersApp', () => ({
        showPaymentsModal: false,
        loadingPayments: false,
        payments: [],
        selectedEventId: null,
        selectedEventTitle: '',
        showFacilityBookingsModal: false,
        loadingFacilityBookings: false,
        facilityBookings: [],
        selectedFacilityId: null,
        selectedFacilityName: '',
        showRefundModal: false,
        refundPayment: null,
        refundReason: '',
        refundAmount: '',
        refunding: false,
        showConfirmModal: false,
        confirmTitle: '',
        confirmMessage: '',
        confirmPrimaryLabel: 'Confirm',
        _confirmAction: null,
        showNoticeModal: false,
        noticeTitle: '',
        noticeMessage: '',
        noticeVariant: 'info',
        _noticeOnClose: null,
        bgSyncToast: '',
        _stripeBgTimer: null,
        init() {
            this.maybeReconcileStripeBackground();
        },
        maybeReconcileStripeBackground() {
            const KEY = 'headcount_stripe_bg_reconcile_ms';
            const intervalMs = 3 * 60 * 60 * 1000;
            if (typeof localStorage === 'undefined') {
                return;
            }
            const last = parseInt(localStorage.getItem(KEY) || '0', 10);
            if (Date.now() - last < intervalMs) {
                return;
            }
            if (this._stripeBgTimer) {
                clearTimeout(this._stripeBgTimer);
            }
            this._stripeBgTimer = setTimeout(() => {
                this.fetchPaymentTransfersJson(API_BASE_URL_PAYMENT_TRANSFERS, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reconcile_organization' })
                })
                    .then(({ data }) => {
                        localStorage.setItem(KEY, String(Date.now()));
                        if (data && data.success && (data.updated || 0) > 0) {
                            const n = data.updated;
                            this.bgSyncToast = n === 1
                                ? 'Stripe: 1 pending checkout was marked paid.'
                                : 'Stripe: ' + n + ' pending checkouts were marked paid.';
                            setTimeout(() => { this.bgSyncToast = ''; }, 8000);
                        }
                    })
                    .catch(() => {
                        localStorage.setItem(KEY, String(Date.now()));
                    });
            }, 4000);
        },
        blurPageFocus() {
            if (typeof document === 'undefined') {
                return;
            }
            const ae = document.activeElement;
            if (ae && typeof ae.blur === 'function') {
                ae.blur();
            }
        },
        openConfirm(title, message, onConfirm, confirmPrimaryLabel = 'Confirm') {
            this.blurPageFocus();
            this.showPaymentsModal = false;
            this.confirmTitle = title;
            this.confirmMessage = message;
            this.confirmPrimaryLabel = confirmPrimaryLabel;
            this._confirmAction = onConfirm;
            this.showConfirmModal = true;
        },
        confirmAccept() {
            const fn = this._confirmAction;
            this.showConfirmModal = false;
            this._confirmAction = null;
            if (typeof fn === 'function') {
                fn();
            }
        },
        confirmDismiss() {
            this.showConfirmModal = false;
            this._confirmAction = null;
        },
        openNotice(title, message, variant = 'info', onClose = null) {
            this.blurPageFocus();
            this.noticeTitle = title;
            this.noticeMessage = message;
            this.noticeVariant = variant;
            this._noticeOnClose = onClose;
            this.showNoticeModal = true;
        },
        noticeOk() {
            const fn = this._noticeOnClose;
            this.showNoticeModal = false;
            this._noticeOnClose = null;
            if (typeof fn === 'function') {
                fn();
            }
        },
        noticeDismiss() {
            this.showNoticeModal = false;
            this._noticeOnClose = null;
        },
        async fetchPaymentTransfersJson(url, options) {
            const response = await fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}));
            const text = await response.text();
            let data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                const snippet = (text || '').trim().substring(0, 180);
                throw new Error(
                    snippet
                        ? 'Server returned non-JSON (HTTP ' + response.status + '): ' + snippet
                        : 'Empty response from server (HTTP ' + response.status + ').'
                );
            }
            return { response, data };
        },
        async viewPayments(eventId, eventTitle) {
            this.blurPageFocus();
            this.selectedEventId = eventId;
            this.selectedEventTitle = eventTitle;
            this.showPaymentsModal = true;
            this.loadingPayments = true;
            this.payments = [];
            try {
                const { response, data } = await this.fetchPaymentTransfersJson(
                    `${API_BASE_URL_PAYMENT_TRANSFERS}?action=get_payments&event_id=${eventId}`
                );
                if (data.success) {
                    this.payments = data.payments || [];
                } else {
                    this.openNotice(
                        'Could not load payments',
                        (data.message || 'Unknown error') + (response.status >= 500 ? ' (HTTP ' + response.status + ')' : ''),
                        'error'
                    );
                }
            } catch (error) {
                console.error('Error loading payments:', error);
                this.openNotice('Could not load payments', error.message || 'An unexpected error occurred. Please try again.', 'error');
            } finally {
                this.loadingPayments = false;
            }
        },
        async viewFacilityBookings(facilityId, facilityName) {
            this.blurPageFocus();
            this.selectedFacilityId = facilityId;
            this.selectedFacilityName = facilityName;
            this.showFacilityBookingsModal = true;
            this.loadingFacilityBookings = true;
            this.facilityBookings = [];
            try {
                const { response, data } = await this.fetchPaymentTransfersJson(
                    `${API_BASE_URL_PAYMENT_TRANSFERS}?action=get_facility_bookings&facility_id=${facilityId}`
                );
                if (data.success) {
                    this.facilityBookings = data.bookings || [];
                } else {
                    this.openNotice(
                        'Could not load bookings',
                        (data.message || 'Unknown error') + (response.status >= 500 ? ' (HTTP ' + response.status + ')' : ''),
                        'error'
                    );
                }
            } catch (error) {
                console.error('Error loading facility bookings:', error);
                this.openNotice('Could not load bookings', error.message || 'An unexpected error occurred. Please try again.', 'error');
            } finally {
                this.loadingFacilityBookings = false;
            }
        },
        formatBookingRange(start, end) {
            const s = new Date(start);
            const e = new Date(end);
            const opts = { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' };
            const endOpts = { hour: '2-digit', minute: '2-digit' };
            return s.toLocaleString(undefined, opts) + ' – ' + e.toLocaleTimeString(undefined, endOpts);
        },
        facilityPaymentStatusLabel(booking) {
            const labels = {
                awaiting_checkout: 'Awaiting checkout',
                authorized: 'Authorized (hold)',
                captured: 'Captured',
                released: 'Hold released',
                failed: 'Failed',
            };
            return labels[String(booking.payment_status || '').toLowerCase()] || 'Unknown';
        },
        facilityPaymentStatusBadgeClass(booking) {
            const s = String(booking.payment_status || '').toLowerCase();
            if (s === 'captured') {
                return 'bg-emerald-100 text-emerald-800 ring-1 ring-inset ring-emerald-200';
            }
            if (s === 'authorized') {
                return 'bg-brand-50 text-brand-800 ring-1 ring-inset ring-brand-200';
            }
            if (s === 'awaiting_checkout') {
                return 'bg-amber-50 text-amber-900 ring-1 ring-inset ring-amber-200';
            }
            if (s === 'failed') {
                return 'bg-rose-50 text-rose-800 ring-1 ring-inset ring-rose-200';
            }
            if (s === 'released') {
                return 'bg-gray-200 text-gray-800 ring-1 ring-inset ring-gray-300';
            }
            return 'bg-gray-100 text-gray-800 ring-1 ring-inset ring-gray-200';
        },
        bookingStatusBadgeClass(status) {
            const s = String(status || '').toLowerCase();
            if (s === 'approved') {
                return 'bg-emerald-100 text-emerald-800 ring-1 ring-inset ring-emerald-200';
            }
            if (s === 'pending') {
                return 'bg-amber-50 text-amber-900 ring-1 ring-inset ring-amber-200';
            }
            if (s === 'rejected') {
                return 'bg-rose-50 text-rose-800 ring-1 ring-inset ring-rose-200';
            }
            return 'bg-gray-200 text-gray-800 ring-1 ring-inset ring-gray-300';
        },
        syncStripeReconcile(eventId, eventTitle) {
            this.openConfirm(
                'Sync with Stripe',
                'Sync pending checkouts for "' + eventTitle + '"?\n\nThis marks payments as paid when Stripe already completed checkout (for example, missed webhooks).',
                () => { this.runStripeReconcile(eventId); },
                'Sync now'
            );
        },
        async runStripeReconcile(eventId) {
            try {
                const { data } = await this.fetchPaymentTransfersJson(API_BASE_URL_PAYMENT_TRANSFERS, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reconcile_event', event_id: eventId })
                });
                if (data.success) {
                    const updated = data.updated || 0;
                    const skipped = data.skipped_unpaid_session || 0;
                    let msg = 'Marked paid in Headcount: ' + updated + '.';
                    if (skipped > 0) {
                        msg += ' Left unchanged (Stripe still shows checkout as incomplete or not paid): ' + skipped + '.';
                    }
                    if (data.errors && data.errors.length) {
                        msg += '\n\nNotes:\n' + data.errors.slice(0, 5).join('\n');
                    }
                    this.openNotice('Stripe sync complete', msg, 'success', () => { window.location.reload(); });
                } else {
                    this.openNotice('Sync failed', data.message || 'Something went wrong.', 'error');
                }
            } catch (error) {
                console.error(error);
                this.openNotice('Sync failed', 'An unexpected error occurred while syncing with Stripe.', 'error');
            }
        },
        formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
        paymentStatusLabel(payment) {
            const s = String(payment.status || '').toLowerCase();
            const ra = parseFloat(payment.refund_amount || 0);
            const amt = parseFloat(payment.amount || 0);
            if (s === 'refunded' || (amt > 0 && ra >= amt)) {
                return 'Refunded';
            }
            if (s === 'paid' && ra > 0 && ra < amt) {
                return 'Partially refunded';
            }
            if (s === 'paid') {
                return 'Paid';
            }
            if (s === 'pending') {
                return 'Pending checkout';
            }
            if (s === 'failed') {
                return 'Failed';
            }
            return payment.status ? String(payment.status).replace(/_/g, ' ') : 'Unknown';
        },
        paymentStatusBadgeClass(payment) {
            const s = String(payment.status || '').toLowerCase();
            const ra = parseFloat(payment.refund_amount || 0);
            const amt = parseFloat(payment.amount || 0);
            if (s === 'refunded' || (amt > 0 && ra >= amt)) {
                return 'bg-gray-200 text-gray-800 ring-1 ring-inset ring-gray-300';
            }
            if (s === 'paid' && ra > 0 && ra < amt) {
                return 'bg-violet-100 text-violet-800 ring-1 ring-inset ring-violet-200';
            }
            if (s === 'paid') {
                return 'bg-emerald-100 text-emerald-800 ring-1 ring-inset ring-emerald-200';
            }
            if (s === 'pending') {
                return 'bg-amber-50 text-amber-900 ring-1 ring-inset ring-amber-200';
            }
            if (s === 'failed') {
                return 'bg-rose-50 text-rose-800 ring-1 ring-inset ring-rose-200';
            }
            return 'bg-gray-100 text-gray-800 ring-1 ring-inset ring-gray-200';
        },
        canRefund(payment) {
            return payment.payment_method !== 'cash'
                && String(payment.status || '').toLowerCase() === 'paid'
                && parseFloat(payment.refund_amount || 0) < parseFloat(payment.amount);
        },
        isFullyRefunded(payment) {
            return String(payment.status || '').toLowerCase() === 'refunded'
                || parseFloat(payment.refund_amount || 0) >= parseFloat(payment.amount);
        },
        openRefundModal(payment) {
            this.blurPageFocus();
            this.refundPayment = payment;
            this.refundReason = '';
            this.refundAmount = '';
            this.showRefundModal = true;
        },
        async submitRefund() {
            if (!this.refundPayment || !this.refundReason.trim()) {
                this.openNotice('Reason required', 'Please enter a reason for the refund.', 'error');
                return;
            }
            this.refunding = true;
            try {
                const body = {
                    action: 'refund',
                    payment_id: this.refundPayment.id,
                    reason: this.refundReason.trim()
                };
                if (this.refundAmount && parseFloat(this.refundAmount) > 0) {
                    body.amount = parseFloat(this.refundAmount);
                }
                const { data } = await this.fetchPaymentTransfersJson(API_BASE_URL_PAYMENT_TRANSFERS, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                if (data.success) {
                    this.showRefundModal = false;
                    this.refundPayment = null;
                    await this.viewPayments(this.selectedEventId, this.selectedEventTitle);
                    this.openNotice('Refund processed', 'The refund was recorded and the attendee will receive a confirmation email.', 'success');
                } else {
                    this.openNotice('Refund failed', data.message || 'Could not process the refund.', 'error');
                }
            } catch (e) {
                console.error(e);
                this.openNotice('Refund failed', 'An unexpected error occurred while processing the refund.', 'error');
            } finally {
                this.refunding = false;
            }
        }
    }));
});
</script>
<div x-data="paymentTransfersApp" x-init="init()" x-cloak
     @keydown.escape.window="if (showConfirmModal) { confirmDismiss(); } else if (showNoticeModal) { noticeDismiss(); } else if (showRefundModal) { showRefundModal = false; } else if (showFacilityBookingsModal) { showFacilityBookingsModal = false; } else if (showPaymentsModal) { showPaymentsModal = false; }">
    <!-- Portaled to body so position:fixed is viewport-relative (avoids overflow/transform from .main-content clipping modals) -->
    <template x-teleport="body">
    <div class="payment-transfers-teleport-root">
    <!-- Toast: background Stripe reconcile -->
    <div x-show="bgSyncToast" x-transition.opacity
         class="pt-modal-toast fixed bottom-6 right-6 flex max-w-sm items-start gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800 shadow-card-lg dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700"
         style="display: none;" role="status">
        <span class="shrink-0 w-8 h-8 rounded-full ta-badge-success flex items-center justify-center mt-0.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
        </span>
        <div class="min-w-0 pt-0.5">
            <p class="font-semibold text-gray-900 dark:text-white">Payments updated</p>
            <p class="text-gray-600 mt-0.5 dark:text-gray-300" x-text="bgSyncToast"></p>
        </div>
        <button type="button" @click="bgSyncToast = ''" class="shrink-0 text-gray-400 hover:text-gray-600 p-1 rounded-lg dark:text-gray-300" aria-label="Dismiss">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>
    <!-- Confirm modal -->
    <div x-show="showConfirmModal" x-transition.opacity class="pt-modal-screen fixed inset-0 flex min-w-0 items-center justify-center overflow-x-hidden overflow-y-auto p-4" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="payment-transfers-confirm-title">
        <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="confirmDismiss()"></div>
        <div class="relative pt-modal-panel w-full min-w-0 max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-card-lg sm:p-8 dark:bg-gray-800 dark:border-gray-700">
            <h4 id="payment-transfers-confirm-title" class="text-lg font-semibold tracking-tight text-gray-900 dark:text-white" x-text="confirmTitle"></h4>
            <p class="mt-3 text-sm leading-relaxed text-gray-600 whitespace-pre-wrap dark:text-gray-300" x-text="confirmMessage"></p>
            <div class="mt-8 flex flex-wrap justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                <button type="button" @click="confirmDismiss()" class="btn-secondary focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 rounded-xl">Cancel</button>
                <button type="button" @click="confirmAccept()" class="btn-primary focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 rounded-xl" x-text="confirmPrimaryLabel"></button>
            </div>
        </div>
    </div>
    <!-- Notice -->
    <div x-show="showNoticeModal" x-transition.opacity class="pt-modal-screen fixed inset-0 flex min-w-0 items-center justify-center overflow-x-hidden overflow-y-auto p-4" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="payment-transfers-notice-title">
        <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="noticeOk()"></div>
        <div class="relative pt-modal-panel w-full min-w-0 max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-card-lg sm:p-8 dark:bg-gray-800 dark:border-gray-700">
            <div class="flex gap-4">
                <div class="shrink-0 flex h-11 w-11 items-center justify-center rounded-full"
                     :class="noticeVariant === 'success' ? 'ta-badge-success' : (noticeVariant === 'error' ? 'ta-badge-error' : 'ta-badge-brand')">
                    <svg x-show="noticeVariant === 'success'" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <svg x-show="noticeVariant === 'error'" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    <svg x-show="noticeVariant === 'info'" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <div class="min-w-0 flex-1 space-y-3">
                    <h4 id="payment-transfers-notice-title" class="text-lg font-semibold tracking-tight text-gray-900 dark:text-white" x-text="noticeTitle"></h4>
                    <p class="text-sm leading-relaxed text-gray-600 whitespace-pre-wrap dark:text-gray-300" x-text="noticeMessage"></p>
                </div>
            </div>
            <div class="mt-8 flex justify-end border-t border-gray-100 pt-6 dark:border-gray-800">
                <button type="button" @click="noticeOk()" class="inline-flex min-h-[2.75rem] min-w-[5.5rem] items-center justify-center rounded-xl border-0 px-6 py-2.5 text-sm font-semibold text-white transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2"
                        :class="noticeVariant === 'error' ? 'bg-rose-600 hover:bg-rose-700 focus:ring-rose-500' : (noticeVariant === 'success' ? 'bg-emerald-600 hover:bg-emerald-700 focus:ring-emerald-500' : 'bg-brand-600 hover:bg-brand-700 focus:ring-brand-500')">OK</button>
            </div>
        </div>
    </div>
    <!-- Refund Modal -->
    <div x-show="showRefundModal" x-transition.opacity class="pt-modal-screen fixed inset-0 flex min-w-0 items-center justify-center overflow-x-hidden overflow-y-auto p-4" style="display: none;">
        <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showRefundModal = false"></div>
        <div class="relative pt-modal-panel w-full min-w-0 max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-card-lg sm:p-8 dark:bg-gray-800 dark:border-gray-700">
            <h4 class="text-lg font-semibold tracking-tight text-gray-900 dark:text-white">Process Refund</h4>
            <template x-if="refundPayment">
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Refund for <span x-text="refundPayment.user_name"></span> — $<span x-text="parseFloat(refundPayment.amount).toFixed(2)"></span></p>
            </template>
            <div class="mt-6 space-y-5">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">Reason (required)</label>
                    <textarea x-model="refundReason" rows="3" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700" placeholder="e.g. Customer request, duplicate charge"></textarea>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">Amount (optional, leave empty for full refund)</label>
                    <input type="number" step="0.01" min="0" x-model="refundAmount" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm dark:border-gray-700" placeholder="Full refund">
                </div>
            </div>
            <div class="mt-8 flex flex-wrap justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                <button type="button" @click="showRefundModal = false" class="btn-secondary focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 rounded-xl">Cancel</button>
                <button type="button" @click="submitRefund()" :disabled="refunding" class="btn-danger hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2 rounded-xl disabled:opacity-60">Process Refund</button>
            </div>
        </div>
    </div>

    <!-- View Payments Modal -->
    <div x-show="showPaymentsModal"
         x-transition.opacity
         class="pt-modal-screen fixed inset-0 flex min-h-0 min-w-0 items-start justify-center overflow-x-hidden overflow-y-auto overscroll-contain p-4 sm:py-8"
         style="display: none;"
         role="dialog"
         aria-modal="true"
         aria-labelledby="payment-transfers-payments-title">
        <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showPaymentsModal = false"></div>
        <div class="relative pt-modal-panel my-4 flex max-h-[90vh] w-full min-h-0 min-w-0 max-w-4xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white text-left shadow-card-lg sm:my-8 dark:bg-gray-800 dark:border-gray-700">
                <div class="shrink-0 border-b border-gray-200 bg-white px-4 py-4 sm:px-6 dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex min-w-0 items-center justify-between gap-3">
                        <h3 id="payment-transfers-payments-title" class="min-w-0 text-lg font-bold leading-snug text-gray-900 sm:text-xl dark:text-white">
                            <span class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Payments</span>
                            <span class="block truncate" x-text="selectedEventTitle"></span>
                        </h3>
                        <button type="button" @click="showPaymentsModal = false" class="shrink-0 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:bg-gray-800 dark:text-gray-300" aria-label="Close">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                </div>
                <div class="min-h-0 min-w-0 flex-1 overflow-y-auto overflow-x-hidden bg-white px-4 py-4 sm:px-6 sm:py-5 dark:bg-gray-800">
                    <div x-show="loadingPayments" class="py-8 text-center">
                        <div class="inline-block h-8 w-8 animate-spin rounded-full border-4 border-brand-500 border-t-transparent"></div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Loading payments...</p>
                    </div>
                    <div x-show="!loadingPayments && payments.length === 0" class="py-8 text-center">
                        <p class="text-gray-500 dark:text-gray-400">No payments found for this event.</p>
                    </div>
                    <div x-show="!loadingPayments && payments.length > 0" class="space-y-4">
                        <template x-for="payment in payments" :key="payment.id">
                            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-colors sm:p-5 dark:bg-gray-800 dark:border-gray-700">
                                <div class="flex flex-col gap-4">
                                    <div class="min-w-0 border-b border-gray-100 pb-3 dark:border-gray-800">
                                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white" x-text="payment.user_name"></p>
                                        <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400" x-text="payment.user_email"></p>
                                    </div>
                                    <dl class="grid grid-cols-1 gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                                        <div class="min-w-0 space-y-1">
                                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Amount</dt>
                                            <dd class="text-base font-bold tabular-nums text-gray-900 dark:text-white" x-text="'$' + parseFloat(payment.amount).toFixed(2)"></dd>
                                        </div>
                                        <div class="min-w-0 space-y-1">
                                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Recorded</dt>
                                            <dd class="text-gray-800 dark:text-gray-100" x-text="formatDate(payment.created_at)"></dd>
                                        </div>
                                        <div class="min-w-0 space-y-1 sm:col-span-2">
                                            <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Payment status</dt>
                                            <dd>
                                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize"
                                                      :class="paymentStatusBadgeClass(payment)"
                                                      x-text="paymentStatusLabel(payment)"></span>
                                            </dd>
                                        </div>
                                    </dl>
                                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                                        <p x-show="payment.payment_method === 'cash'" class="mr-auto text-xs text-gray-600 dark:text-gray-300">Paid in <span class="font-medium text-gray-900 dark:text-white">cash</span> at the door</p>
                                        <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                                            <button type="button"
                                                    x-show="canRefund(payment)"
                                                    @click="openRefundModal(payment)"
                                                    class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-1">
                                                Refund
                                            </button>
                                            <span x-show="isFullyRefunded(payment)" class="text-xs font-medium text-gray-500 dark:text-gray-400">Fully refunded</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
        </div>
    </div>

    <!-- View Facility Bookings Modal -->
    <div x-show="showFacilityBookingsModal"
         x-transition.opacity
         class="pt-modal-screen fixed inset-0 flex min-h-0 min-w-0 items-start justify-center overflow-x-hidden overflow-y-auto overscroll-contain p-4 sm:py-8"
         style="display: none;"
         role="dialog"
         aria-modal="true"
         aria-labelledby="payment-transfers-facility-bookings-title">
        <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showFacilityBookingsModal = false"></div>
        <div class="relative pt-modal-panel my-4 flex max-h-[90vh] w-full min-h-0 min-w-0 max-w-4xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white text-left shadow-card-lg sm:my-8 dark:bg-gray-800 dark:border-gray-700">
            <div class="shrink-0 border-b border-gray-200 bg-white px-4 py-4 sm:px-6 dark:bg-gray-800 dark:border-gray-700">
                <div class="flex min-w-0 items-center justify-between gap-3">
                    <h3 id="payment-transfers-facility-bookings-title" class="min-w-0 text-lg font-bold leading-snug text-gray-900 sm:text-xl dark:text-white">
                        <span class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Facility bookings &amp; payments</span>
                        <span class="block truncate" x-text="selectedFacilityName"></span>
                    </h3>
                    <button type="button" @click="showFacilityBookingsModal = false" class="shrink-0 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:bg-gray-800 dark:text-gray-300" aria-label="Close">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>
            <div class="min-h-0 min-w-0 flex-1 overflow-y-auto overflow-x-hidden bg-white px-4 py-4 sm:px-6 sm:py-5 dark:bg-gray-800">
                <div x-show="loadingFacilityBookings" class="py-8 text-center">
                    <div class="inline-block h-8 w-8 animate-spin rounded-full border-4 border-brand-500 border-t-transparent"></div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Loading bookings...</p>
                </div>
                <div x-show="!loadingFacilityBookings && facilityBookings.length === 0" class="py-8 text-center">
                    <p class="text-gray-500 dark:text-gray-400">No paid facility bookings found.</p>
                </div>
                <div x-show="!loadingFacilityBookings && facilityBookings.length > 0" class="space-y-4">
                    <template x-for="booking in facilityBookings" :key="booking.id">
                        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-colors sm:p-5 dark:bg-gray-800 dark:border-gray-700">
                            <div class="flex flex-col gap-4">
                                <div class="min-w-0 border-b border-gray-100 pb-3 dark:border-gray-800">
                                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white" x-text="booking.title"></p>
                                    <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400" x-text="formatBookingRange(booking.start_datetime, booking.end_datetime)"></p>
                                    <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400" x-text="booking.user_name + ' · ' + booking.user_email"></p>
                                </div>
                                <dl class="grid grid-cols-1 gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                                    <div class="min-w-0 space-y-1">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Amount</dt>
                                        <dd class="text-base font-bold tabular-nums text-gray-900 dark:text-white" x-text="booking.total_amount ? ('$' + parseFloat(booking.total_amount).toFixed(2)) : '—'"></dd>
                                    </div>
                                    <div class="min-w-0 space-y-1">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Booked via</dt>
                                        <dd class="capitalize text-gray-800 dark:text-gray-100" x-text="booking.booked_via || 'portal'"></dd>
                                    </div>
                                    <div class="min-w-0 space-y-1">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Booking status</dt>
                                        <dd>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize"
                                                  :class="bookingStatusBadgeClass(booking.status)"
                                                  x-text="booking.status"></span>
                                        </dd>
                                    </div>
                                    <div class="min-w-0 space-y-1">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Payment status</dt>
                                        <dd>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                                  :class="facilityPaymentStatusBadgeClass(booking)"
                                                  x-text="facilityPaymentStatusLabel(booking)"></span>
                                        </dd>
                                    </div>
                                    <div class="min-w-0 space-y-1 sm:col-span-2" x-show="booking.purpose">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Purpose</dt>
                                        <dd class="text-gray-800 dark:text-gray-100" x-text="booking.purpose"></dd>
                                    </div>
                                    <div class="min-w-0 space-y-1" x-show="booking.payment_captured_at">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Captured</dt>
                                        <dd class="text-gray-800 dark:text-gray-100" x-text="formatDate(booking.payment_captured_at)"></dd>
                                    </div>
                                    <div class="min-w-0 space-y-1" x-show="booking.payment_authorized_at && !booking.payment_captured_at">
                                        <dt class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Authorized</dt>
                                        <dd class="text-gray-800 dark:text-gray-100" x-text="formatDate(booking.payment_authorized_at)"></dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    </div>
    </template>

    <?php
    $pageHeaderTitle = 'Payments';
    $pageHeaderSubtitle = 'Stripe Checkout for paid-ticket events and facility bookings. Use Events for ticket payments, Facilities for booking holds and captures, and Reports for charts. Pending checkouts also refresh in the background (throttled).';
    $pageHeaderActions = '';
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3 md:gap-6">
        <?php if ($tab === 'facilities'): ?>
        <?php
        $statLabel = 'Captured revenue';
        $statValue = '$' . number_format($totalFacCaptured, 2);
        $statTrend = null;
        $statTrendLabel = 'Charged on approval';
        $statAccent = 'success';
        $statIcon = 'currency';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Authorized holds';
        $statValue = number_format($totalFacAuthorizedCount);
        $statTrend = null;
        $statTrendLabel = '$' . number_format($totalFacAuthorized, 2) . ' pending capture';
        $statAccent = 'brand';
        $statIcon = 'chart';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Awaiting / failed';
        $statValue = number_format($totalFacAwaitingCount + $totalFacFailedCount);
        $statTrend = null;
        $statTrendLabel = number_format($totalFacAwaitingCount) . ' awaiting · ' . number_format($totalFacFailedCount) . ' failed';
        $statAccent = 'warning';
        $statIcon = 'ticket';
        require __DIR__ . '/components/stat-card-trend.php';
        ?>
        <?php else: ?>
        <?php
        $statLabel = 'Total collected';
        $statValue = '$' . number_format($totalCollected, 2);
        $statTrend = null;
        $statTrendLabel = 'Paid Stripe / card rows';
        $statAccent = 'success';
        $statIcon = 'currency';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Unpaid checkouts';
        $statValue = number_format($totalPendingCheckoutCount);
        $statTrend = null;
        $statTrendLabel = '$' . number_format($totalPendingCheckoutAmount, 2) . ' in pending rows';
        $statAccent = 'warning';
        $statIcon = 'chart';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Failed';
        $statValue = number_format($totalFailedCount);
        $statTrend = null;
        $statTrendLabel = '$' . number_format($totalFailedAmount, 2) . ' in failed rows';
        $statAccent = 'rose';
        $statIcon = 'ticket';
        require __DIR__ . '/components/stat-card-trend.php';
        ?>
        <?php endif; ?>
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-6 border-b border-gray-200 pb-4 dark:border-gray-700">
        <a href="<?= e($paymentsTabEventsUrl) ?>"
           class="border-b-2 pb-2 text-xs font-bold uppercase tracking-widest transition-colors <?= $tab === 'events' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' ?>">
            Events
        </a>
        <a href="<?= e($paymentsTabFacilitiesUrl) ?>"
           class="border-b-2 pb-2 text-xs font-bold uppercase tracking-widest transition-colors <?= $tab === 'facilities' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' ?>">
            Facilities
        </a>
        <a href="<?= e($paymentsTabReportsUrl) ?>"
           class="border-b-2 pb-2 text-xs font-bold uppercase tracking-widest transition-colors <?= $tab === 'reports' ? 'border-brand-600 text-brand-600 dark:border-brand-400 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' ?>">
            Reports
        </a>
    </div>

    <?php if ($tab === 'events'): ?>
    <!-- Filters -->
    <div class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <form method="GET" action="<?= e($adminBase . '/?page=payment-transfers') ?>" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <input type="hidden" name="page" value="payment-transfers">
            <input type="hidden" name="tab" value="events">
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 dark:text-gray-300">Status</label>
                <select name="status" class="ta-select w-full">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All events</option>
                    <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Has unpaid checkouts</option>
                    <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Has failed payments</option>
                    <option value="collected" <?= $status === 'collected' ? 'selected' : '' ?>>Has collected revenue</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 dark:text-gray-300">Search</label>
                <div class="relative">
                    <span class="absolute left-3 top-2.5 text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </span>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search events..."
                           class="ta-input w-full pl-10">
                </div>
            </div>
            <div class="flex gap-2 items-end">
                <button type="submit" class="btn-primary flex-1">Filter</button>
                <a href="<?= e($adminBase . '/?page=payment-transfers&tab=events') ?>" class="btn-secondary text-sm grid place-content-center py-2.5 px-4">Reset</a>
            </div>
        </form>
    </div>

    <?php if (empty($events)): ?>
        <div class="bento-card p-12 text-center">
            <div class="w-16 h-16 bg-gray-50 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <p class="text-gray-500 font-medium mb-4 dark:text-gray-400">No paid-ticket events with payment rows match your filters.</p>
        </div>
    <?php else:
        $tableTitle = 'Paid-ticket events';
        $tableColumns = [
            ['key' => 'title', 'label' => 'Event'],
            ['key' => 'event_date', 'label' => 'Date'],
            ['key' => 'ticket_price', 'label' => 'Ticket price', 'class' => 'text-right'],
            ['key' => 'completed_payment_count', 'label' => 'Paid', 'class' => 'text-right'],
            ['key' => 'pending_checkout_count', 'label' => 'Unpaid', 'class' => 'text-right'],
            ['key' => 'failed_payment_count', 'label' => 'Failed', 'class' => 'text-right'],
            ['key' => 'payment_count', 'label' => 'Total', 'class' => 'text-right'],
            ['key' => 'total_collected', 'label' => 'Collected', 'class' => 'text-right'],
            ['key' => 'pending_checkout_amount', 'label' => 'Unpaid $', 'class' => 'text-right'],
            ['key' => 'failed_amount', 'label' => 'Failed $', 'class' => 'text-right'],
            ['key' => 'actions', 'label' => 'Actions', 'type' => 'actions', 'class' => 'text-right'],
        ];
        $tableRows = [];
        foreach ($events as $event) {
            $dateHtml = e(formatDate($event['event_date']));
            if (!empty($event['start_time'])) {
                $dateHtml .= '<br><span class="text-theme-xs text-gray-400">' . e(formatTime($event['start_time'])) . '</span>';
            }
            $actions = '<div class="inline-flex flex-col sm:flex-row gap-2 justify-end">';
            if ((int) ($event['pending_checkout_count'] ?? 0) > 0) {
                $actions .= '<button type="button" @click="syncStripeReconcile(' . (int) $event['id'] . ', \'' . e(addslashes($event['title'])) . '\')" class="btn-secondary bg-amber-50 text-amber-900 border-amber-200 hover:bg-amber-100 py-1.5 px-2.5 text-xs">Sync Stripe</button>';
            }
            $actions .= '<button type="button" @click="viewPayments(' . (int) $event['id'] . ', \'' . e(addslashes($event['title'])) . '\')" class="btn-secondary py-1.5 px-2.5 text-xs">View Payments</button></div>';
            $tableRows[] = [
                'title' => (string) ($event['title'] ?? ''),
                'event_date' => $dateHtml,
                'ticket_price' => '$' . number_format((float) ($event['ticket_price'] ?? 0), 2),
                'completed_payment_count' => (string) (int) ($event['completed_payment_count'] ?? 0),
                'pending_checkout_count' => (string) (int) ($event['pending_checkout_count'] ?? 0),
                'failed_payment_count' => (string) (int) ($event['failed_payment_count'] ?? 0),
                'payment_count' => (string) (int) ($event['payment_count'] ?? 0),
                'total_collected' => '$' . number_format((float) ($event['total_collected'] ?? 0), 2),
                'pending_checkout_amount' => '$' . number_format((float) ($event['pending_checkout_amount'] ?? 0), 2),
                'failed_amount' => '$' . number_format((float) ($event['failed_amount'] ?? 0), 2),
                'actions_html' => $actions,
            ];
        }
        foreach ($tableColumns as &$col) {
            if (($col['key'] ?? '') === 'event_date') {
                $col['raw'] = true;
                $col['raw_key'] = 'event_date';
            }
        }
        unset($col);
        $tableEmptyMessage = 'No paid-ticket events with payment rows match your filters.';
        require __DIR__ . '/components/data-table.php';
    endif; ?>

    <?php elseif ($tab === 'facilities'): ?>
    <?php if (!$facilityPaymentsEnabled): ?>
        <div class="bento-card p-12 text-center">
            <p class="text-gray-500 font-medium mb-4 dark:text-gray-400">Facility booking payments are not available yet. Run migration 062_facility_booking_stripe.sql to enable Stripe holds and captures for facility bookings.</p>
            <a href="<?= e(rtrim($adminBase, '/') . '/?page=facility-bookings') ?>" class="btn-secondary inline-flex">Open facility bookings</a>
        </div>
    <?php else: ?>
    <div class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <form method="GET" action="<?= e($adminBase . '/?page=payment-transfers') ?>" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <input type="hidden" name="page" value="payment-transfers">
            <input type="hidden" name="tab" value="facilities">
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 dark:text-gray-300">Payment status</label>
                <select name="fac_status" class="ta-select w-full">
                    <option value="all" <?= $facStatus === 'all' ? 'selected' : '' ?>>All paid bookings</option>
                    <option value="captured" <?= $facStatus === 'captured' ? 'selected' : '' ?>>Captured</option>
                    <option value="authorized" <?= $facStatus === 'authorized' ? 'selected' : '' ?>>Authorized (hold)</option>
                    <option value="awaiting" <?= $facStatus === 'awaiting' ? 'selected' : '' ?>>Awaiting checkout</option>
                    <option value="failed" <?= $facStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 dark:text-gray-300">Search</label>
                <div class="relative">
                    <span class="absolute left-3 top-2.5 text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </span>
                    <input type="text" name="fac_search" value="<?= e($facSearch) ?>" placeholder="Search facilities..."
                           class="ta-input w-full pl-10">
                </div>
            </div>
            <div class="flex gap-2 items-end">
                <button type="submit" class="btn-primary flex-1">Filter</button>
                <a href="<?= e($adminBase . '/?page=payment-transfers&tab=facilities') ?>" class="btn-secondary text-sm grid place-content-center py-2.5 px-4">Reset</a>
            </div>
        </form>
    </div>

    <p class="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">Facility bookings authorize a card hold when requested; payment is captured when staff approve the booking. Holds expire after about 7 days if not reviewed.</p>

    <?php if (empty($facilitiesWithPayments)): ?>
        <div class="bento-card p-12 text-center">
            <div class="w-16 h-16 bg-gray-50 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
            </div>
            <p class="text-gray-500 font-medium mb-4 dark:text-gray-400">No facilities with paid bookings match your filters.</p>
            <a href="<?= e(rtrim($adminBase, '/') . '/?page=facility-bookings') ?>" class="btn-secondary inline-flex">Manage facility bookings</a>
        </div>
    <?php else:
        $tableTitle = 'Facilities with paid bookings';
        $tableColumns = [
            ['key' => 'name', 'label' => 'Facility'],
            ['key' => 'booking_count', 'label' => 'Bookings', 'class' => 'text-right'],
            ['key' => 'captured_count', 'label' => 'Captured', 'class' => 'text-right'],
            ['key' => 'authorized_count', 'label' => 'Authorized', 'class' => 'text-right'],
            ['key' => 'awaiting_count', 'label' => 'Awaiting', 'class' => 'text-right'],
            ['key' => 'failed_count', 'label' => 'Failed', 'class' => 'text-right'],
            ['key' => 'captured_amount', 'label' => 'Captured $', 'class' => 'text-right'],
            ['key' => 'authorized_amount', 'label' => 'Hold $', 'class' => 'text-right'],
            ['key' => 'actions', 'label' => 'Actions', 'type' => 'actions', 'class' => 'text-right'],
        ];
        $tableRows = [];
        foreach ($facilitiesWithPayments as $facRow) {
            $nameHtml = e((string) ($facRow['name'] ?? ''));
            if (!empty($facRow['location'])) {
                $nameHtml .= '<br><span class="text-theme-xs text-gray-400">' . e((string) $facRow['location']) . '</span>';
            }
            $actions = '<button type="button" @click="viewFacilityBookings(' . (int) $facRow['id'] . ', \'' . e(addslashes($facRow['name'])) . '\')" class="btn-secondary py-1.5 px-2.5 text-xs">View bookings</button>';
            $tableRows[] = [
                'name' => $nameHtml,
                'booking_count' => (string) (int) ($facRow['booking_count'] ?? 0),
                'captured_count' => (string) (int) ($facRow['captured_count'] ?? 0),
                'authorized_count' => (string) (int) ($facRow['authorized_count'] ?? 0),
                'awaiting_count' => (string) (int) ($facRow['awaiting_count'] ?? 0),
                'failed_count' => (string) (int) ($facRow['failed_count'] ?? 0),
                'captured_amount' => '$' . number_format((float) ($facRow['captured_amount'] ?? 0), 2),
                'authorized_amount' => '$' . number_format((float) ($facRow['authorized_amount'] ?? 0), 2),
                'actions_html' => $actions,
            ];
        }
        foreach ($tableColumns as &$col) {
            if (($col['key'] ?? '') === 'name') {
                $col['raw'] = true;
                $col['raw_key'] = 'name';
            }
        }
        unset($col);
        $tableEmptyMessage = 'No facilities with paid bookings match your filters.';
        require __DIR__ . '/components/data-table.php';
    endif; ?>
    <?php endif; ?>

    <?php elseif ($tab === 'reports'): ?>
    <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <?php
            $chartCardTitle = 'Payment rows by status';
            $chartCardId = 'ptStatusDonut';
            $chartCardHeight = '300px';
            require __DIR__ . '/components/chart-card.php';
            ?>
        </div>
        <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <?php
            $chartCardTitle = 'Top events by collected revenue';
            $chartCardId = 'ptTopEventsBar';
            $chartCardHeight = '320px';
            require __DIR__ . '/components/chart-card.php';
            ?>
        </div>
    </div>
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Paid revenue by day';
        $chartCardSubtitle = 'Last 90 days';
        $chartCardId = 'ptPaidTrend';
        $chartCardHeight = '280px';
        require __DIR__ . '/components/chart-card.php';
        ?>
    </div>
    <?php endif; ?>

</div>

<?php if ($tab === 'reports' && is_array($paymentTransfersChartData)): ?>
<script>
window.PAYMENT_TRANSFERS_CHART_DATA = <?= json_encode($paymentTransfersChartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>

