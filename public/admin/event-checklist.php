<?php

/**
 * Event checklist — internal ops task manager for an event.
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
$isSuperAdmin = AuthMiddleware::isSuperAdmin();
$canManageEvents = AuthMiddleware::can('events.manage');

$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

$eventId = (int) ($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    http_response_code(404);
    die('Event not found.');
}

$svc = new EventChecklistService($db);
$event = $db->queryOne(
    'SELECT e.* FROM events e WHERE e.id = :id AND e.organization_id = :oid',
    ['id' => $eventId, 'oid' => $organizationId]
);
if (!$event) {
    http_response_code(404);
    die('Event not found.');
}

$storageId = EventChecklistService::storageEventId($event);
$canManage = $svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin);
$items = $svc->listItemsForEvent($eventId, $organizationId);
$progress = $svc->progressForEvent($eventId, $organizationId);
$leadership = $svc->getLeadership($storageId);
$roles = $svc->listRoles($organizationId);
$staff = $svc->listEligibleAssignees($organizationId);

$phaseLabels = [
    'pre' => 'Phase 1: Pre-Event (Planning & Preparation)',
    'day_of' => 'Phase 2: Event Proper (Day-of Execution)',
    'post' => 'Phase 3: Post-Event (Wrap-up & Evaluation)',
];

$grouped = [];
foreach ($items as $item) {
    $phase = $item['phase'] ?? 'pre';
    $section = $item['section'] ?? '';
    if (!isset($grouped[$phase])) {
        $grouped[$phase] = [];
    }
    if (!isset($grouped[$phase][$section])) {
        $grouped[$phase][$section] = [];
    }
    $grouped[$phase][$section][] = $item;
}

$apiBase = rtrim($basePath ?? '', '/') . '/public/api/event-checklist.php';
$pageHeaderTitle = $event['title'] ?? 'Event checklist';
$pageHeaderSubtitle = trim(
    ($event['category'] ?? '') . ' · ' .
    ($event['event_date'] ?? '') .
    ($event['start_time'] ? ' · ' . substr($event['start_time'], 0, 5) : '') .
    ($event['location'] ? ' · ' . $event['location'] : '')
);
$targetPax = $event['target_attendance'] ?? null;
$budget = isset($event['budget']) ? $event['budget'] : null;

require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in space-y-6" x-data="eventChecklistPage(<?= htmlspecialchars(json_encode([
    'eventId' => $eventId,
    'apiBase' => $apiBase,
    'canManage' => $canManage,
    'canManageEvents' => $canManageEvents || $canManage,
    'staff' => $staff,
    'roles' => $roles,
    'status' => $event['status'] ?? 'draft',
]), ENT_QUOTES, 'UTF-8') ?>)">

    <?php
    ob_start();
    ?>
    <a href="<?= e($navUrls['events'] ?? ($adminBase . '/?page=events')) ?>" class="btn-secondary text-sm">Back to list</a>
    <a href="<?= e($adminBase . '/?page=event-details&id=' . $eventId) ?>" class="btn-secondary text-sm">Event details</a>
    <template x-if="status !== 'completed' && canManageEvents">
        <button type="button" @click="markDone()" class="btn-primary text-sm">Mark event done</button>
    </template>
    <template x-if="status === 'completed' && canManageEvents">
        <button type="button" @click="reopenEvent()" class="btn-secondary text-sm">Reopen</button>
    </template>
    <?php if ($canManage): ?>
    <button type="button" @click="replaceTemplate()" class="btn-secondary text-sm">Replace from template</button>
    <?php endif; ?>
    <?php
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="bento-card p-6">
        <div class="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-300 mb-4">
            <?php if ($targetPax): ?><span>Target <strong><?= (int) $targetPax ?></strong> pax</span><?php endif; ?>
            <?php if ($budget !== null && $budget !== ''): ?><span>Budget <strong>$<?= number_format((float) $budget, 0) ?></strong></span><?php endif; ?>
            <span class="capitalize">Status: <strong><?= e($event['status'] ?? 'draft') ?></strong></span>
        </div>

        <div class="mb-2 flex justify-between text-sm">
            <span>Checklist: <?= (int) $progress['complete'] ?>/<?= (int) $progress['total'] ?> complete (<?= (int) $progress['pct'] ?>%)</span>
            <?php if ($progress['in_progress'] > 0): ?>
            <span class="text-amber-700 dark:text-amber-400"><?= (int) $progress['in_progress'] ?> in progress</span>
            <?php endif; ?>
        </div>
        <div class="h-2 bg-gray-200 rounded-full overflow-hidden dark:bg-gray-700">
            <div class="h-full bg-brand-600 transition-all" style="width: <?= min(100, max(0, (int) $progress['pct'])) ?>%"></div>
        </div>

        <?php if (!empty($leadership)): ?>
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            <?php foreach ($leadership as $lead): ?>
            <div class="rounded-lg border border-gray-200 bg-gray-50/80 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/50">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?= e($lead['role_label'] ?? '') ?></p>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= e(trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''))) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="bento-card p-6">
        <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-1">Event checklist</h2>
        <p class="text-sm text-gray-500 mb-6 dark:text-gray-400">Phase-based task manager</p>

        <?php foreach (['pre', 'day_of', 'post'] as $phaseKey): ?>
        <details class="mb-4 border border-gray-200 rounded-xl dark:border-gray-700" <?= $phaseKey === 'pre' ? 'open' : '' ?>>
            <summary class="cursor-pointer px-4 py-3 font-semibold text-gray-800 flex justify-between dark:text-gray-100">
                <span><?= e($phaseLabels[$phaseKey] ?? $phaseKey) ?></span>
                <span class="text-sm font-normal text-gray-500">
                    <?php
                    $phaseItems = array_merge(...array_values($grouped[$phaseKey] ?? [[]]));
                    if ($phaseItems === []) {
                        $phaseItems = [];
                    }
                    $phaseComplete = 0;
                    foreach ($items as $it) {
                        if (($it['phase'] ?? '') === $phaseKey && ($it['status'] ?? '') === 'complete') {
                            $phaseComplete++;
                        }
                    }
                    $phaseTotal = 0;
                    foreach ($items as $it) {
                        if (($it['phase'] ?? '') === $phaseKey) {
                            $phaseTotal++;
                        }
                    }
                    echo $phaseComplete . '/' . $phaseTotal;
                    ?>
                </span>
            </summary>
            <div class="px-4 pb-4 space-y-6">
                <?php
                $sections = $grouped[$phaseKey] ?? [];
                if ($sections === []):
                ?>
                <p class="text-sm text-gray-500 py-2">No tasks in this phase.</p>
                <?php else: ?>
                <?php foreach ($sections as $sectionName => $sectionItems): ?>
                <div>
                    <h3 class="text-sm font-bold text-gray-800 mb-3 dark:text-gray-100"><?= e($sectionName) ?></h3>
                    <div class="space-y-3">
                        <?php foreach ($sectionItems as $task): ?>
                        <div class="rounded-xl border border-gray-200 p-4 <?= ($task['status'] ?? '') === 'in_progress' ? 'bg-amber-50/50 dark:bg-amber-900/10' : 'bg-white dark:bg-gray-800/30' ?> dark:border-gray-700"
                             data-item-id="<?= (int) $task['id'] ?>">
                            <p class="font-medium text-gray-900 mb-3 dark:text-gray-100"><?= e($task['title']) ?></p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Status</label>
                                    <select class="ta-select w-full text-sm checklist-status-select"
                                        data-item-id="<?= (int) $task['id'] ?>"
                                        <?= (!$canManage && (int)($task['assignee_user_id'] ?? 0) !== $userId) ? 'disabled' : '' ?>>
                                        <option value="not_started" <?= ($task['status'] ?? '') === 'not_started' ? 'selected' : '' ?>>Not started</option>
                                        <option value="in_progress" <?= ($task['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                                        <option value="complete" <?= ($task['status'] ?? '') === 'complete' ? 'selected' : '' ?>>Complete</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Assigned lead</label>
                                    <select class="ta-select w-full text-sm checklist-assignee-select"
                                        data-item-id="<?= (int) $task['id'] ?>"
                                        <?= !$canManage ? 'disabled' : '' ?>>
                                        <option value="">— Unassigned —</option>
                                        <?php foreach ($staff as $person): ?>
                                        <option value="<?= (int) $person['id'] ?>"
                                            <?= (int)($task['assignee_user_id'] ?? 0) === (int)$person['id'] ? 'selected' : '' ?>>
                                            <?= e($person['first_name'] . ' ' . $person['last_name']) ?>
                                            <?php if (!empty($task['role_label'])): ?>(<?= e($task['role_label']) ?>)<?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Due date</label>
                                    <input type="date" class="ta-input w-full text-sm checklist-due-input"
                                        data-item-id="<?= (int) $task['id'] ?>"
                                        value="<?= e($task['due_date'] ?? '') ?>"
                                        <?= !$canManage ? 'disabled' : '' ?>>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('alpine:init', function() {
    Alpine.data('eventChecklistPage', function(cfg) {
        return {
            eventId: cfg.eventId,
            apiBase: cfg.apiBase,
            canManage: cfg.canManage,
            canManageEvents: cfg.canManageEvents,
            status: cfg.status,
            patchItem: async function(itemId, payload) {
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({ action: 'update_item', event_id: this.eventId, item_id: itemId }, payload))
                });
                const data = await res.json();
                if (!data.success) alert(data.message || 'Update failed');
                else location.reload();
            },
            markDone: async function() {
                if (!confirm('Mark this event as completed?')) return;
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'mark_event_done', event_id: this.eventId })
                });
                const data = await res.json();
                if (data.success) location.reload();
                else alert(data.message || 'Failed');
            },
            reopenEvent: async function() {
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reopen_event', event_id: this.eventId })
                });
                const data = await res.json();
                if (data.success) location.reload();
                else alert(data.message || 'Failed');
            },
            replaceTemplate: async function() {
                if (!confirm('Replace all checklist tasks with the current category template? Existing progress will be lost.')) return;
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'replace_template', event_id: this.eventId })
                });
                const data = await res.json();
                if (data.success) location.reload();
                else alert(data.message || 'Failed');
            }
        };
    });
});

document.querySelectorAll('.checklist-status-select').forEach(function(el) {
    el.addEventListener('change', function() {
        var root = document.querySelector('[x-data]');
        var itemId = parseInt(el.getAttribute('data-item-id'), 10);
        fetch('<?= e($apiBase) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_item', event_id: <?= (int) $eventId ?>, item_id: itemId, status: el.value })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) alert(d.message || 'Failed');
            else location.reload();
        });
    });
});
document.querySelectorAll('.checklist-assignee-select').forEach(function(el) {
    el.addEventListener('change', function() {
        var itemId = parseInt(el.getAttribute('data-item-id'), 10);
        fetch('<?= e($apiBase) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_item', event_id: <?= (int) $eventId ?>, item_id: itemId, assignee_user_id: el.value || null })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) alert(d.message || 'Failed');
            else location.reload();
        });
    });
});
document.querySelectorAll('.checklist-due-input').forEach(function(el) {
    el.addEventListener('change', function() {
        var itemId = parseInt(el.getAttribute('data-item-id'), 10);
        fetch('<?= e($apiBase) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_item', event_id: <?= (int) $eventId ?>, item_id: itemId, due_date: el.value || null })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) alert(d.message || 'Failed');
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
