<?php
/**
 * Event request detail + review actions
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
if (!$service->tablesExist()) {
    http_response_code(503);
    echo 'Event requests are not installed.';
    exit;
}

$id = (int) get('id', 0);
$request = $id > 0 ? $service->getById($id, $organizationId) : null;
if (!$request) {
    if (!isset($adminBase)) {
        require_once __DIR__ . '/includes/layout-vars.php';
    }
    setFlash('error', 'Event request not found.');
    redirect($adminBase . '/index.php?page=event-requests');
    exit;
}
$isOwner = (int) $request['submitted_by'] === $userId;
if (!$canApprove && !$isOwner) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (!isset($adminBase)) {
    require_once __DIR__ . '/includes/layout-vars.php';
}

if (isPost()) {
    CsrfMiddleware::verify();
    $action = (string) post('request_action', '');
    try {
        if ($action === 'withdraw' && $isOwner) {
            $service->withdraw($id, $organizationId, $userId);
            setFlash('success', 'Request withdrawn.');
            redirect($adminBase . '/index.php?page=event-requests');
            exit;
        }
        if ($action === 'send_back' && $canApprove) {
            $service->sendBack($id, $organizationId, $userId, (string) post('comment', ''));
            setFlash('success', 'Request sent back for updates. The submitter has been notified.');
        } elseif ($action === 'decline' && $canApprove) {
            $service->decline($id, $organizationId, $userId, (string) post('comment', ''));
            setFlash('success', 'Request declined. The submitter has been notified.');
        } elseif ($action === 'approve' && $canApprove) {
            $eventId = $service->approve($id, $organizationId, $userId, (string) post('comment', ''));
            setFlash('success', 'Request approved. A draft event was created.');
            redirect($adminBase . '/index.php?page=event-edit&id=' . (int) $eventId);
            exit;
        } else {
            setFlash('error', 'You cannot perform that action.');
        }
    } catch (\Throwable $e) {
        setFlash('error', $e->getMessage());
    }
    redirect($adminBase . '/index.php?page=event-request-details&id=' . $id);
    exit;
}

$comments = $service->commentsFor($id);
$pageTitle = 'Event Request';
$currentPage = 'event-request-details';
require __DIR__ . '/includes/header.php';
$flash = getFlash();
$pending = ($request['status'] ?? '') === EventRequestService::STATUS_PENDING;
$needsUpdates = ($request['status'] ?? '') === EventRequestService::STATUS_CHANGES_REQUESTED;
$approved = ($request['status'] ?? '') === EventRequestService::STATUS_APPROVED;

$fmtDate = !empty($request['event_date']) ? date('F j, Y', strtotime($request['event_date'])) : '—';
$fmtTime = '';
if (!empty($request['start_time'])) {
    $fmtTime = date('g:i A', strtotime($request['start_time']));
    if (!empty($request['end_time'])) {
        $fmtTime .= ' – ' . date('g:i A', strtotime($request['end_time']));
    }
}
?>

<div class="animate-fade-in max-w-4xl mx-auto">
    <?php
    $pageHeaderTitle = $request['title'];
    $pageHeaderSubtitle = 'Submitted by ' . ($request['submitter_name'] ?? '') . ' · ' . ucfirst(str_replace('_', ' ', (string) $request['status']));
    $pageHeaderBreadcrumb = [
        ['label' => 'Events', 'url' => $adminBase . '/index.php?page=events'],
        ['label' => 'Event Requests', 'url' => $adminBase . '/index.php?page=event-requests'],
        ['label' => 'Details'],
    ];
    ob_start();
    if ($needsUpdates && $isOwner): ?>
        <a class="page-header-btn-primary" href="<?= e($adminBase . '/index.php?page=event-request-form&id=' . $id) ?>">Edit and resubmit</a>
    <?php endif;
    if ($approved && !empty($request['event_id'])): ?>
        <a class="page-header-btn-primary" href="<?= e($adminBase . '/index.php?page=event-edit&id=' . (int) $request['event_id']) ?>">Open draft event</a>
    <?php endif;
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if ($flash): ?>
        <div class="ta-alert <?= ($flash['type'] ?? '') === 'success' ? 'ta-alert-success' : 'ta-alert-error' ?> mb-6">
            <p><?= e($flash['message'] ?? '') ?></p>
        </div>
    <?php endif; ?>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] mb-6">
        <dl class="grid gap-4 sm:grid-cols-2 text-theme-sm">
            <div><dt class="text-gray-500">Date</dt><dd class="font-medium text-gray-900 dark:text-white/90"><?= e($fmtDate) ?></dd></div>
            <div><dt class="text-gray-500">Time</dt><dd class="font-medium text-gray-900 dark:text-white/90"><?= e($fmtTime !== '' ? $fmtTime : '—') ?></dd></div>
            <div><dt class="text-gray-500">Location</dt><dd class="font-medium text-gray-900 dark:text-white/90"><?= e($request['location'] ?: 'TBD') ?></dd></div>
            <div><dt class="text-gray-500">Category</dt><dd class="font-medium text-gray-900 dark:text-white/90"><?= e($request['category'] ?: '—') ?></dd></div>
            <div><dt class="text-gray-500">Budget</dt><dd class="font-medium text-gray-900 dark:text-white/90"><?= $request['budget'] !== null && $request['budget'] !== '' ? '$' . e(number_format((float) $request['budget'], 2)) : '—' ?></dd></div>
            <div><dt class="text-gray-500">Expected attendance</dt><dd class="font-medium text-gray-900 dark:text-white/90"><?= e((string) ($request['target_attendance'] ?: '—')) ?></dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500">Target audience</dt><dd class="font-medium text-gray-900 dark:text-white/90"><?= e($request['target_audience'] ?: '—') ?></dd></div>
        </dl>
        <div class="mt-6">
            <h2 class="mb-2 text-theme-sm font-semibold text-gray-800 dark:text-white/90">Description</h2>
            <div class="prose prose-sm max-w-none text-gray-700 dark:text-gray-300"><?= nl2br(e($request['description'])) ?></div>
        </div>
        <?php if (!empty($request['notes'])): ?>
            <div class="mt-4">
                <h2 class="mb-2 text-theme-sm font-semibold text-gray-800 dark:text-white/90">Notes to reviewers</h2>
                <p class="text-gray-700 dark:text-gray-300"><?= nl2br(e($request['notes'])) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canApprove && $pending): ?>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] mb-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white">Review</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(CsrfMiddleware::getToken()) ?>">
                <div>
                    <label class="form-label" for="comment">Comment</label>
                    <textarea class="ta-input w-full" id="comment" name="comment" rows="3" placeholder="Required when sending back or declining"></textarea>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button class="btn-primary" type="submit" name="request_action" value="approve">Approve and create draft</button>
                    <button class="btn-secondary" type="submit" name="request_action" value="send_back">Send back</button>
                    <button class="rounded-lg bg-error-600 px-4 py-2 text-theme-sm font-semibold text-white hover:bg-error-700" type="submit" name="request_action" value="decline">Decline</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($isOwner && in_array($request['status'], [EventRequestService::STATUS_PENDING, EventRequestService::STATUS_CHANGES_REQUESTED], true)): ?>
        <form method="post" class="mb-6" onsubmit="return confirm('Withdraw this request?');">
            <input type="hidden" name="csrf_token" value="<?= e(CsrfMiddleware::getToken()) ?>">
            <button class="btn-secondary" type="submit" name="request_action" value="withdraw">Withdraw request</button>
        </form>
    <?php endif; ?>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white">History</h2>
        <?php if (empty($comments)): ?>
            <p class="text-gray-500">No comments yet.</p>
        <?php else: ?>
            <ol class="space-y-4">
                <?php foreach ($comments as $c): ?>
                    <li class="border-l-2 border-gray-200 pl-4 dark:border-gray-700">
                        <div class="text-theme-sm font-semibold text-gray-800 dark:text-white/90"><?= e($c['user_name'] ?? '') ?> · <?= e(str_replace('_', ' ', (string) $c['action'])) ?></div>
                        <div class="text-theme-xs text-gray-500"><?= e($c['created_at'] ?? '') ?></div>
                        <?php if (!empty($c['message'])): ?>
                            <p class="mt-1 text-theme-sm text-gray-700 dark:text-gray-300"><?= nl2br(e($c['message'])) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
