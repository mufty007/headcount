<?php

/**
 * My Tasks — checklist items assigned to the current admin/coordinator.
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
use Headcount\Services\EventChecklistService;

AuthMiddleware::requireAdminOrCoordinator();
AuthMiddleware::requireCan('checklists.view');

$organizationId = (int) AuthMiddleware::getOrganizationId();
$userId = (int) AuthMiddleware::getUserId();

$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

$filter = $_GET['filter'] ?? 'open';
if (!in_array($filter, ['open', 'complete', 'all'], true)) {
    $filter = 'open';
}

$svc = new EventChecklistService($db);
$tasks = $svc->tablesExist()
    ? $svc->listItemsForAssignee($organizationId, $userId, $filter === 'all' ? null : $filter)
    : [];

$apiBase = rtrim($basePath ?? '', '/') . '/public/api/event-checklist.php';
$pageHeaderTitle = 'My Tasks';
$pageHeaderSubtitle = 'Checklist items assigned to you across events.';

require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in space-y-6">
    <?php
    $pageHeaderActions = '';
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="flex gap-2 mb-4">
        <a href="<?= e($adminBase . '/?page=my-tasks&filter=open') ?>"
           class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $filter === 'open' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' ?>">Open</a>
        <a href="<?= e($adminBase . '/?page=my-tasks&filter=complete') ?>"
           class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $filter === 'complete' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' ?>">Complete</a>
        <a href="<?= e($adminBase . '/?page=my-tasks&filter=all') ?>"
           class="px-3 py-1.5 rounded-lg text-sm font-medium <?= $filter === 'all' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' ?>">All</a>
    </div>

    <?php if (empty($tasks)): ?>
    <div class="bento-card p-8 text-center text-gray-500 dark:text-gray-400">
        <p>No tasks in this view.</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <?php
        $byEvent = [];
        foreach ($tasks as $t) {
            $eid = (int) ($t['event_id'] ?? 0);
            if (!isset($byEvent[$eid])) {
                $byEvent[$eid] = ['title' => $t['event_title'] ?? 'Event', 'date' => $t['event_date'] ?? '', 'tasks' => []];
            }
            $byEvent[$eid]['tasks'][] = $t;
        }
        foreach ($byEvent as $eid => $group):
        ?>
        <div class="bento-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <div>
                    <h2 class="font-bold text-gray-900 dark:text-gray-100"><?= e($group['title']) ?></h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><?= e($group['date']) ?></p>
                </div>
                <a href="<?= e($adminBase . '/?page=event-checklist&event_id=' . (int) $eid) ?>" class="btn-secondary text-xs py-1.5">Open checklist</a>
            </div>
            <div class="space-y-3">
                <?php foreach ($group['tasks'] as $task): ?>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <p class="font-medium text-sm text-gray-900 mb-2 dark:text-gray-100"><?= e($task['title']) ?></p>
                    <div class="flex flex-wrap gap-3 items-center text-xs text-gray-500 dark:text-gray-400">
                        <?php if (!empty($task['role_label'])): ?><span><?= e($task['role_label']) ?></span><?php endif; ?>
                        <?php if (!empty($task['due_date'])): ?><span>Due <?= e($task['due_date']) ?></span><?php endif; ?>
                        <select class="ta-select text-xs py-1 my-task-status" data-item-id="<?= (int) $task['id'] ?>" data-event-id="<?= (int) $eid ?>">
                            <option value="not_started" <?= ($task['status'] ?? '') === 'not_started' ? 'selected' : '' ?>>Not started</option>
                            <option value="in_progress" <?= ($task['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                            <option value="complete" <?= ($task['status'] ?? '') === 'complete' ? 'selected' : '' ?>>Complete</option>
                        </select>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.my-task-status').forEach(function(el) {
    el.addEventListener('change', function() {
        fetch('<?= e($apiBase) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_item',
                event_id: parseInt(el.getAttribute('data-event-id'), 10),
                item_id: parseInt(el.getAttribute('data-item-id'), 10),
                status: el.value
            })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) alert(d.message || 'Update failed');
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
