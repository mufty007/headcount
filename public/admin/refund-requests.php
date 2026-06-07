<?php

/**
 * Admin Refund Requests Page
 * List and approve/deny user-initiated refund requests
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

$userData = $db->queryOne("SELECT first_name, last_name, email FROM users WHERE id = :id", ['id' => AuthMiddleware::getUserId()]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email']
] : ['name' => 'Administrator', 'email' => 'admin@headcount.local'];

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
$basePath = preg_replace('#/admin/.*$#', '', $requestPath);
$basePath = rtrim($basePath, '/');
$adminBase = $basePath . '/admin';
$apiBase = $basePath . '/api';

$pageTitle = 'Refund Requests';
$currentPage = 'refund-requests';
require __DIR__ . '/includes/header.php';
?>

<?php
$pageHeaderTitle = 'Refund Requests';
$pageHeaderSubtitle = 'Review and approve or deny refund requests from attendees who did not check in.';
$pageHeaderActions = '';
require __DIR__ . '/components/page-header.php';
?>

<div x-data="refundRequestsApp()" x-init="load()">
    <div class="mb-4 flex gap-2">
        <button @click="status = 'pending'; load()" :class="status === 'pending' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-slate-800 dark:text-slate-200'" class="rounded-lg px-4 py-2 text-sm font-medium">Pending</button>
        <button @click="status = 'all'; load()" :class="status === 'all' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-slate-800 dark:text-slate-200'" class="rounded-lg px-4 py-2 text-sm font-medium">All</button>
    </div>
    <div x-show="loading" class="text-center py-12">
        <div class="inline-block animate-spin w-10 h-10 border-4 border-indigo-500 border-t-transparent rounded-full"></div>
        <p class="mt-2 text-gray-500 dark:text-slate-400">Loading...</p>
    </div>
    <div x-show="!loading && requests.length === 0" class="bento-card py-12 text-center text-gray-500 dark:text-slate-400">No refund requests found.</div>
    <div x-show="!loading && requests.length > 0" class="space-y-4">
        <template x-for="r in requests" :key="r.id">
            <div class="bento-card flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <div class="font-bold text-gray-900 dark:text-white" x-text="r.first_name + ' ' + r.last_name"></div>
                    <div class="text-sm text-gray-500 dark:text-slate-400" x-text="r.user_email"></div>
                    <div class="mt-1 text-sm">
                        <span class="font-medium text-gray-800 dark:text-slate-200" x-text="r.event_title"></span>
                        <span class="text-gray-500 dark:text-slate-500" x-text="'\u2014 ' + (r.event_date || '')"></span>
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-slate-400" x-text="'Reason: ' + r.reason"></p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-500" x-text="'Amount: $' + (r.payment_amount ? parseFloat(r.payment_amount).toFixed(2) : '0.00')"></p>
                    <span class="mt-2 inline-block rounded px-2 py-0.5 text-xs font-bold uppercase"
                          :class="r.status === 'pending' ? 'ta-badge-warning' : (r.status === 'approved' ? 'ta-badge-success' : 'bg-gray-200 text-gray-600 dark:bg-slate-700 dark:text-slate-300')"
                          x-text="r.status"></span>
                </div>
                <div x-show="r.status === 'pending'" class="flex gap-2 flex-shrink-0">
                    <button @click="approve(r.id)" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700">Approve</button>
                    <button @click="openDeny(r)" class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-300 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600">Deny</button>
                </div>
            </div>
        </template>
    </div>

    <div x-show="showDenyModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showDenyModal = false"></div>
        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-card-lg dark:border-slate-600 dark:bg-slate-900">
            <h4 class="mb-2 text-lg font-bold text-gray-900 dark:text-white">Deny Refund Request</h4>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">Note to user (optional)</label>
            <textarea x-model="denyNotes" rows="3" class="mb-4 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100" placeholder="e.g. Event policy does not allow refunds after the event date."></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" @click="showDenyModal = false" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">Cancel</button>
                <button type="button" @click="deny(denyRequestId)" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700">Deny request</button>
            </div>
        </div>
    </div>
</div>

<script>
function refundRequestsApp() {
    return {
        status: 'pending',
        requests: [],
        loading: false,
        showDenyModal: false,
        denyRequestId: null,
        denyNotes: '',
        apiBase: <?= json_encode($apiBase) ?>,

        async load() {
            this.loading = true;
            try {
                const res = await fetch(this.apiBase + '/refund-requests.php?status=' + this.status);
                const data = await res.json();
                this.requests = data.success ? (data.requests || []) : [];
            } catch (e) {
                console.error(e);
            } finally {
                this.loading = false;
            }
        },
        async approve(requestId) {
            if (!confirm('Process refund via Stripe and notify the user?')) return;
            try {
                const res = await fetch(this.apiBase + '/refund-requests.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'approve', request_id: requestId })
                });
                const data = await res.json();
                alert(data.success ? data.message : (data.message || 'Failed'));
                if (data.success) this.load();
            } catch (e) {
                alert('Request failed');
            }
        },
        openDeny(requestId) {
            this.denyRequestId = requestId;
            this.denyNotes = '';
            this.showDenyModal = true;
        },
        async deny(requestId) {
            try {
                const res = await fetch(this.apiBase + '/refund-requests.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'deny', request_id: requestId, admin_notes: this.denyNotes })
                });
                const data = await res.json();
                this.showDenyModal = false;
                this.denyRequestId = null;
                alert(data.success ? data.message : (data.message || 'Failed'));
                if (data.success) this.load();
            } catch (e) {
                alert('Request failed');
            }
        }
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

