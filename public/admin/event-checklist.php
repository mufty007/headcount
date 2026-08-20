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
$hasChecklistItems = count($items) > 0;

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
    'hasItems' => $hasChecklistItems,
    'phaseLabels' => $phaseLabels,
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
    <template x-if="!hasItems">
        <button type="button" @click="generateChecklist()" class="btn-primary text-sm">Generate checklist</button>
    </template>
    <template x-if="hasItems">
        <button type="button" @click="replaceTemplate()" class="btn-secondary text-sm">Replace from template</button>
    </template>
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
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-1">Event checklist</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Phase-based task manager — template tasks auto-assign to leadership roles; add custom tasks anytime.</p>
            </div>
            <?php if ($canManage): ?>
            <button type="button" @click="openAddForm('pre')" class="btn-secondary text-sm shrink-0">+ Add task</button>
            <?php endif; ?>
        </div>

        <?php if (!$hasChecklistItems): ?>
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-100">
            <p class="font-semibold mb-1">No checklist tasks yet</p>
            <p class="mb-3">Tasks are normally created automatically when you save a new event with leadership assigned. For existing events, click <strong>Generate checklist</strong> to load the ~40-task template for this event’s category (assignments use your leadership team above).</p>
            <?php if ($canManage): ?>
            <button type="button" @click="generateChecklist()" class="btn-primary text-sm">Generate checklist from template</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Add task panel -->
        <div x-show="showAddForm" x-cloak class="mb-6 rounded-xl border border-brand-200 bg-brand-50/40 p-4 dark:border-brand-800 dark:bg-brand-950/20">
            <h3 class="text-sm font-semibold text-gray-900 mb-3 dark:text-gray-100">Add custom task</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="md:col-span-2">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Task title *</label>
                    <input type="text" x-model="addForm.title" class="ta-input w-full mt-1" placeholder="e.g. Confirm dessert table setup">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Phase</label>
                    <select x-model="addForm.phase" class="ta-select w-full mt-1">
                        <?php foreach ($phaseLabels as $pKey => $pLabel): ?>
                        <option value="<?= e($pKey) ?>"><?= e($pLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Section (group label)</label>
                    <input type="text" x-model="addForm.section" class="ta-input w-full mt-1" placeholder="Custom">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Default role (optional)</label>
                    <select x-model="addForm.role_id" class="ta-select w-full mt-1">
                        <option value="">— None —</option>
                        <template x-for="role in roles" :key="role.id">
                            <option :value="role.id" x-text="role.label"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Assign to (optional)</label>
                    <select x-model="addForm.assignee_user_id" class="ta-select w-full mt-1">
                        <option value="">— Auto from role —</option>
                        <template x-for="person in staff" :key="person.id">
                            <option :value="person.id" x-text="person.first_name + ' ' + person.last_name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Due date (optional)</label>
                    <input type="date" x-model="addForm.due_date" class="ta-input w-full mt-1">
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" @click="addTask()" class="btn-primary text-sm">Save task</button>
                <button type="button" @click="showAddForm = false" class="btn-secondary text-sm">Cancel</button>
            </div>
        </div>

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
                            <div class="flex items-start justify-between gap-2 mb-3">
                                <p class="font-medium text-gray-900 dark:text-gray-100"><?= e($task['title']) ?></p>
                                <?php if ($canManage && empty($task['template_task_id'])): ?>
                                <button type="button" class="checklist-delete-btn text-xs text-red-600 hover:underline shrink-0"
                                    data-item-id="<?= (int) $task['id'] ?>">Remove</button>
                                <?php endif; ?>
                            </div>
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

                <?php if ($canManage): ?>
                <button type="button" @click="openAddForm('<?= e($phaseKey) ?>')"
                    class="text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400">+ Add task to this phase</button>
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
            hasItems: !!cfg.hasItems,
            phaseLabels: cfg.phaseLabels || {},
            roles: cfg.roles || [],
            staff: cfg.staff || [],
            showAddForm: false,
            addForm: { phase: 'pre', title: '', section: 'Custom', role_id: '', assignee_user_id: '', due_date: '' },
            openAddForm: function(phase) {
                this.addForm = { phase: phase || 'pre', title: '', section: 'Custom', role_id: '', assignee_user_id: '', due_date: '' };
                this.showAddForm = true;
                window.scrollTo({ top: document.querySelector('[x-show="showAddForm"]')?.offsetTop || 0, behavior: 'smooth' });
            },
            generateChecklist: async function() {
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'generate', event_id: this.eventId, notify: true })
                });
                const data = await res.json();
                if (!data.success) {
                    alert(data.message || 'Could not generate checklist. Check Settings → Event checklists for templates.');
                    return;
                }
                if ((data.created || 0) === 0 && this.hasItems) {
                    alert('Checklist already has tasks.');
                    return;
                }
                location.reload();
            },
            addTask: async function() {
                if (!(this.addForm.title || '').trim()) {
                    alert('Task title is required.');
                    return;
                }
                const payload = {
                    action: 'add_item',
                    event_id: this.eventId,
                    title: this.addForm.title.trim(),
                    phase: this.addForm.phase,
                    section: (this.addForm.section || 'Custom').trim() || 'Custom',
                };
                if (this.addForm.role_id) payload.role_id = parseInt(this.addForm.role_id, 10);
                if (this.addForm.assignee_user_id) payload.assignee_user_id = parseInt(this.addForm.assignee_user_id, 10);
                if (this.addForm.due_date) payload.due_date = this.addForm.due_date;
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) {
                    alert(data.message || 'Failed to add task');
                    return;
                }
                location.reload();
            },
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
document.querySelectorAll('.checklist-delete-btn').forEach(function(el) {
    el.addEventListener('click', function() {
        if (!confirm('Remove this custom task?')) return;
        var itemId = parseInt(el.getAttribute('data-item-id'), 10);
        fetch('<?= e($apiBase) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_item', event_id: <?= (int) $eventId ?>, item_id: itemId })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) alert(d.message || 'Failed');
            else location.reload();
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
