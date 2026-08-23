<?php
/**
 * Event requests queue
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
use Headcount\Services\EventRequestService;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = (int) AuthMiddleware::getOrganizationId();
$userId = (int) AuthMiddleware::getUserId();
$canRequest = AuthMiddleware::can('events.request');
$canApprove = AuthMiddleware::can('events.approve_requests');
if (!$canRequest && !$canApprove) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$config = require HC_PROJECT_ROOT . '/config/config.php';
Database::getInstance($config['database']);
$service = new EventRequestService();

$statusFilter = get('status', $canApprove ? EventRequestService::STATUS_PENDING : 'all');
$filters = [];
if ($statusFilter !== 'all') {
    $filters['status'] = $statusFilter;
}
if (!$canApprove) {
    $filters['submitted_by'] = $userId;
}

$requests = [];
$tablesOk = false;
$loadError = null;
try {
    $tablesOk = $service->tablesExist();
    if ($tablesOk) {
        $requests = $service->listForOrg($organizationId, $filters);
    }
} catch (\Throwable $e) {
    $loadError = $e->getMessage();
}

require_once __DIR__ . '/includes/layout-vars.php';
$pageTitle = 'Event Requests';
$currentPage = 'event-requests';
require __DIR__ . '/includes/header.php';

$flash = getFlash();
$statusTabs = [
    EventRequestService::STATUS_PENDING => 'Pending',
    EventRequestService::STATUS_CHANGES_REQUESTED => 'Needs updates',
    EventRequestService::STATUS_APPROVED => 'Approved',
    EventRequestService::STATUS_DECLINED => 'Declined',
    'all' => 'All',
];
$statusBadge = [
    'pending' => ['Pending', 'warning'],
    'changes_requested' => ['Needs updates', 'brand'],
    'approved' => ['Approved', 'success'],
    'declined' => ['Declined', 'error'],
];
?>

<div class="animate-fade-in">
    <?php
    $pageHeaderTitle = 'Event Requests';
    $pageHeaderSubtitle = $canApprove
        ? 'Review staff proposals, send them back for updates, or approve them into a draft event.'
        : 'Submit an event idea for review. You will be notified when it is sent back, approved, or declined.';
    $pageHeaderBreadcrumb = [
        ['label' => 'Events', 'url' => $adminBase . '/index.php?page=events'],
        ['label' => 'Event Requests'],
    ];
    ob_start();
    if ($canRequest): ?>
        <a href="<?= e($adminBase . '/index.php?page=event-request-form') ?>" class="page-header-btn-primary">Request Event</a>
    <?php endif;
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if ($flash): ?>
        <div class="ta-alert <?= ($flash['type'] ?? '') === 'success' ? 'ta-alert-success' : 'ta-alert-error' ?> mb-6">
            <p><?= e($flash['message'] ?? '') ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$tablesOk): ?>
        <div class="ta-alert ta-alert-warning">Run migration 084_event_requests.sql to enable event requests.<?= $loadError ? ' ' . e($loadError) : '' ?></div>
    <?php else: ?>
        <div class="mb-6 flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-2 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <?php foreach ($statusTabs as $st => $label): ?>
                <a href="<?= e($adminBase . '/index.php?page=event-requests&status=' . urlencode($st)) ?>"
                   class="rounded-lg px-4 py-2 text-theme-sm font-semibold transition-colors <?= $statusFilter === $st ? 'bg-brand-600 text-white shadow-theme-xs' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05]' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($requests)): ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-gray-500 dark:text-gray-400">No event requests in this view.</p>
            </div>
        <?php else: ?>
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                    <thead class="bg-gray-50 dark:bg-white/[0.02]">
                        <tr>
                            <th class="px-4 py-3 text-left text-theme-xs font-semibold text-gray-500">Event</th>
                            <th class="px-4 py-3 text-left text-theme-xs font-semibold text-gray-500">When</th>
                            <?php if ($canApprove): ?><th class="px-4 py-3 text-left text-theme-xs font-semibold text-gray-500">Submitted by</th><?php endif; ?>
                            <th class="px-4 py-3 text-left text-theme-xs font-semibold text-gray-500">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <?php foreach ($requests as $row):
                            $badge = $statusBadge[$row['status']] ?? ['Unknown', 'gray'];
                            ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-gray-900 dark:text-white/90"><?= e($row['title']) ?></div>
                                    <?php if (!empty($row['target_audience'])): ?>
                                        <div class="text-theme-xs text-gray-500"><?= e($row['target_audience']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">
                                    <?= e(date('M j, Y', strtotime($row['event_date']))) ?>
                                    <?php if (!empty($row['start_time'])): ?>
                                        <div class="text-theme-xs text-gray-500"><?= e(date('g:i A', strtotime($row['start_time']))) ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canApprove): ?>
                                    <td class="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300"><?= e($row['submitter_name'] ?? '') ?></td>
                                <?php endif; ?>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"><?= e($badge[0]) ?></span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a class="text-theme-sm font-semibold text-brand-600 hover:underline" href="<?= e($adminBase . '/index.php?page=event-request-details&id=' . (int) $row['id']) ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
