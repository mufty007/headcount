<?php

/**
 * Admin Refund Requests Page
 * List and approve/deny user-initiated refund requests
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

$organizationId = AuthMiddleware::getOrganizationId();
$config = require HC_PROJECT_ROOT . '/config/config.php';
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
    <div class="mb-6 flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <button @click="status = 'pending'; load()" :class="status === 'pending' ? 'bg-brand-600 text-white shadow-theme-xs' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.05]'" class="rounded-lg px-4 py-2 text-theme-sm font-semibold transition-colors">Pending</button>
        <button @click="status = 'all'; load()" :class="status === 'all' ? 'bg-brand-600 text-white shadow-theme-xs' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.05]'" class="rounded-lg px-4 py-2 text-theme-sm font-semibold transition-colors">All</button>
    </div>
    <div x-show="loading" class="rounded-2xl border border-gray-200 bg-white py-12 text-center shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="inline-block h-10 w-10 animate-spin rounded-full border-4 border-brand-500 border-t-transparent"></div>
        <p class="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">Loading...</p>
    </div>
    <div x-show="!loading && requests.length === 0" class="rounded-2xl border border-gray-200 bg-white py-12 text-center text-theme-sm text-gray-500 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400">No refund requests found.</div>
    <div x-show="!loading && requests.length > 0" class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
        <div class="w-full overflow-x-auto custom-scrollbar">
            <table class="min-w-full">
                <thead>
                    <tr class="border-y border-gray-100 dark:border-gray-800">
                        <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                        <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Event</p></th>
                        <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reason</p></th>
                        <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Amount</p></th>
                        <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</p></th>
                        <th class="py-3 pr-4 text-right"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</p></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="r in requests" :key="r.id">
                        <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] dark:bg-gray-800">
                            <td class="py-3 pr-4">
                                <div class="flex items-center gap-3">
                                    <span class="ta-avatar ta-avatar-sm bg-brand-100 text-brand-700" x-text="(r.first_name || '?')[0] + (r.last_name || '')[0]"></span>
                                    <div class="min-w-0">
                                        <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="r.first_name + ' ' + r.last_name"></span>
                                        <span class="block text-theme-xs text-gray-500 dark:text-gray-400" x-text="r.user_email"></span>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300">
                                <span class="font-medium text-gray-800 dark:text-white/90" x-text="r.event_title"></span>
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400" x-text="r.event_date || ''"></span>
                            </td>
                            <td class="max-w-xs py-3 pr-4 text-theme-sm text-gray-600 dark:text-gray-400" x-text="r.reason"></td>
                            <td class="py-3 pr-4 text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="'$' + (r.payment_amount ? parseFloat(r.payment_amount).toFixed(2) : '0.00')"></td>
                            <td class="py-3 pr-4">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium"
                                      :class="{
                                          'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400': r.status === 'pending',
                                          'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400': r.status === 'approved',
                                          'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': r.status !== 'pending' && r.status !== 'approved'
                                      }"
                                      x-text="r.status"></span>
                            </td>
                            <td class="py-3 pr-4 text-right">
                                <div x-show="r.status === 'pending'" class="inline-flex gap-2">
                                    <button @click="approve(r.id)" class="rounded-lg bg-success-600 px-3 py-1.5 text-theme-sm font-medium text-white hover:bg-success-700">Approve</button>
                                    <button @click="openDeny(r)" class="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:bg-gray-800">Deny</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <div x-show="showDenyModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showDenyModal = false"></div>
        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-card-lg dark:border-gray-600 dark:bg-gray-900">
            <h4 class="mb-2 text-lg font-bold text-gray-900 dark:text-white">Deny Refund Request</h4>
            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Note to user (optional)</label>
            <textarea x-model="denyNotes" rows="3" class="mb-4 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100" placeholder="e.g. Event policy does not allow refunds after the event date."></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" @click="showDenyModal = false" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
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

