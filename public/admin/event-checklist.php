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
$canManage = $svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin, $canManageEvents);
$items = $svc->listItemsForEvent($eventId, $organizationId);
$progress = $svc->progressForEvent($eventId, $organizationId);
$leadership = $svc->getLeadership($storageId);
$roles = $svc->listRoles($organizationId);
$staff = $svc->listStaffForEventChecklist($organizationId, $eventId);

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
$openPickerOnLoad = $canManage && !empty($_GET['picker']);

$checklistItemsInitial = [];
foreach ($items as $it) {
    $assigneeId = !empty($it['assignee_user_id']) ? (int) $it['assignee_user_id'] : 0;
    $dueRaw = $it['due_date'] ?? '';
    $dueDate = $dueRaw !== '' && $dueRaw !== null ? substr((string) $dueRaw, 0, 10) : '';
    $checklistItemsInitial[] = [
        'id' => (int) $it['id'],
        'title' => $it['title'] ?? '',
        'phase' => $it['phase'] ?? 'pre',
        'section' => $it['section'] ?? '',
        'status' => $it['status'] ?? 'not_started',
        'assignee_user_id' => $assigneeId > 0 ? (string) $assigneeId : '',
        'assignee_label' => trim(($it['assignee_first_name'] ?? '') . ' ' . ($it['assignee_last_name'] ?? '')),
        'due_date' => $dueDate,
        'role_label' => $it['role_label'] ?? '',
        'can_edit' => $canManage || $assigneeId === $userId,
        'can_edit_assignee' => $canManage,
        'can_delete' => $canManage,
    ];
}

require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in space-y-6 pb-24" x-data="eventChecklistPage(<?= htmlspecialchars(json_encode([
    'eventId' => $eventId,
    'apiBase' => $apiBase,
    'canManage' => $canManage,
    'canManageEvents' => $canManageEvents || $canManage,
    'staff' => $staff,
    'roles' => $roles,
    'status' => $event['status'] ?? 'draft',
    'hasItems' => $hasChecklistItems,
    'phaseLabels' => $phaseLabels,
    'openPicker' => $openPickerOnLoad,
    'initialItems' => $checklistItemsInitial,
    'userId' => $userId,
]), ENT_QUOTES, 'UTF-8') ?>)" x-init="init()">

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
        <button type="button" @click="openTaskPicker('generate')" class="btn-primary text-sm">Generate checklist</button>
    </template>
    <template x-if="hasItems">
        <button type="button" @click="openTaskPicker('merge')" class="btn-secondary text-sm">Add from template</button>
        <button type="button" @click="openTaskPicker('replace')" class="btn-secondary text-sm">Replace from template</button>
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
            <span x-text="'Checklist: ' + progressStats().complete + '/' + progressStats().total + ' complete (' + progressStats().pct + '%)'"></span>
            <span x-show="progressStats().in_progress > 0" x-cloak class="text-amber-700 dark:text-amber-400"
                x-text="progressStats().in_progress + ' in progress'"></span>
        </div>
        <div class="h-2 bg-gray-200 rounded-full overflow-hidden dark:bg-gray-700">
            <div class="h-full bg-brand-600 transition-all" :style="'width:' + progressStats().pct + '%'"></div>
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
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    <span x-show="viewMode === 'list'">Edit tasks below, then click <strong>Save changes</strong> when finished.</span>
                    <span x-show="viewMode === 'kanban'" x-cloak>Drag cards between columns to update status, then click <strong>Save changes</strong>.</span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <div x-show="hasItems" x-cloak class="checklist-view-toggle inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700">
                    <button type="button" @click="setViewMode('list')" :class="viewMode === 'list' ? 'is-active' : ''">List</button>
                    <button type="button" @click="setViewMode('kanban')" :class="viewMode === 'kanban' ? 'is-active' : ''">Kanban</button>
                </div>
                <?php if ($canManage): ?>
                <button type="button" @click="openAddForm('pre')" class="btn-secondary text-sm">+ Add custom task</button>
                <?php if ($hasChecklistItems): ?>
                <button type="button" @click="openTaskPicker('merge')" class="btn-secondary text-sm">Add from template</button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div x-show="hasUnsavedChanges" x-cloak
            class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm dark:border-brand-800 dark:bg-brand-950/30">
            <p class="text-brand-900 dark:text-brand-100">
                <span class="font-semibold">Unsaved changes</span> — save when you are done editing tasks.
            </p>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="discardChanges()" class="btn-secondary text-sm" :disabled="saving">Discard</button>
                <button type="button" @click="saveAll()" class="btn-primary text-sm" :disabled="saving">
                    <span x-text="saving ? 'Saving…' : 'Save changes'"></span>
                </button>
            </div>
        </div>

        <?php if (!$hasChecklistItems): ?>
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-100">
            <p class="font-semibold mb-1">No checklist tasks yet</p>
            <p class="mb-3">Click <strong>Generate checklist</strong> to choose which template tasks to add for this event. You can remove tasks afterward if this event doesn’t need them.</p>
            <?php if ($canManage): ?>
            <button type="button" @click="openTaskPicker('generate')" class="btn-primary text-sm">Choose tasks from template</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Task picker modal -->
        <div x-show="showPicker" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @keydown.escape.window="showPicker = false">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-xl w-full max-w-3xl max-h-[90vh] flex flex-col" @click.outside="showPicker = false">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100" x-text="pickerTitle()"></h3>
                    <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">
                        <span x-text="pickerTemplateName"></span>
                        · <span x-text="pickerSelectedCount()"></span> selected
                    </p>
                </div>
                <div class="px-6 py-3 border-b border-gray-100 flex flex-wrap gap-2 dark:border-gray-800">
                    <button type="button" @click="pickerSelectAll(true)" class="text-xs font-medium text-brand-600 hover:underline">Select all</button>
                    <button type="button" @click="pickerSelectAll(false)" class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-400">Clear all</button>
                    <template x-for="(label, key) in phaseLabels" :key="'pick-' + key">
                        <button type="button" @click="pickerSelectPhase(key, true)" class="text-xs font-medium text-gray-600 hover:underline dark:text-gray-400" x-text="'All ' + label.split(':')[0]"></button>
                    </template>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-4 space-y-6">
                    <template x-if="pickerLoading">
                        <p class="text-sm text-gray-500">Loading template tasks…</p>
                    </template>
                    <template x-if="!pickerLoading && pickerTasks.length === 0">
                        <p class="text-sm text-gray-500">No template tasks found. Configure templates in Settings → Event checklists.</p>
                    </template>
                    <template x-for="phaseKey in ['pre', 'day_of', 'post']" :key="phaseKey">
                        <div x-show="pickerTasksByPhase(phaseKey).length">
                            <h4 class="text-sm font-bold text-gray-800 mb-2 dark:text-gray-100" x-text="phaseLabels[phaseKey] || phaseKey"></h4>
                            <div class="space-y-4">
                                <template x-for="section in pickerSectionsInPhase(phaseKey)" :key="phaseKey + '-' + section.name">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2 dark:text-gray-400" x-text="section.name || 'General'"></p>
                                        <div class="space-y-2">
                                            <template x-for="task in section.tasks" :key="task.id">
                                                <label class="flex items-start gap-3 rounded-lg border border-gray-200 px-3 py-2 cursor-pointer hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/50"
                                                    :class="task.already_added ? 'opacity-60' : ''">
                                                    <input type="checkbox" class="mt-1" :checked="!!pickerSelected[task.id]" @change="togglePickerTask(task.id, $event.target.checked)">
                                                    <span class="flex-1 min-w-0">
                                                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100" x-text="task.title"></span>
                                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                                            <span x-text="task.role_label || 'Unassigned role'"></span>
                                                            <template x-if="task.already_added"><span class="text-amber-600 dark:text-amber-400"> · Already on checklist</span></template>
                                                        </span>
                                                    </span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex flex-wrap gap-2 justify-end dark:border-gray-700">
                    <button type="button" @click="showPicker = false" class="btn-secondary text-sm">Cancel</button>
                    <button type="button" @click="confirmTaskPicker()" class="btn-primary text-sm" :disabled="pickerSelectedCount() === 0 || pickerSubmitting">
                        <span x-text="pickerSubmitting ? 'Adding…' : pickerConfirmLabel()"></span>
                    </button>
                </div>
            </div>
        </div>

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
                            <option :value="String(person.id)" x-text="person.first_name + ' ' + person.last_name"></option>
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

        <!-- Kanban view -->
        <div x-show="viewMode === 'kanban' && hasItems" x-cloak class="checklist-kanban-shell mb-4">
            <div class="checklist-kanban-board">
                <template x-for="col in kanbanColumns" :key="col.key">
                    <section class="checklist-kanban-column"
                        :class="[
                            'checklist-kanban-column--' + col.key,
                            kanbanDropTarget === col.key ? 'checklist-kanban-column--active' : ''
                        ]"
                        @dragover.prevent="kanbanDragOver(col.key)"
                        @dragleave="kanbanDragLeave(col.key, $event)"
                        @drop.prevent="kanbanDrop(col.key, $event)">
                        <header class="checklist-kanban-column-header">
                            <div class="checklist-kanban-column-title">
                                <span class="checklist-kanban-column-dot" aria-hidden="true"></span>
                                <span x-text="col.label"></span>
                            </div>
                            <span class="checklist-kanban-column-count" x-text="itemsByStatus(col.key).length"></span>
                        </header>
                        <div class="checklist-kanban-column-body">
                            <div class="checklist-kanban-empty" x-show="itemsByStatus(col.key).length === 0" x-cloak>
                                <svg class="h-5 w-5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/>
                                </svg>
                                <span>Drop tasks here</span>
                            </div>
                            <template x-for="task in itemsByStatus(col.key)" :key="'kanban-' + task.id">
                                <article class="checklist-kanban-card"
                                    :class="{
                                        'checklist-kanban-card--dragging': kanbanDragTaskId === task.id,
                                        'checklist-kanban-card--readonly': !canKanbanDrag(task),
                                        'checklist-kanban-card--pending-delete': pendingDeleteIds.includes(task.id)
                                    }"
                                    :draggable="canKanbanDrag(task)"
                                    @dragstart="kanbanDragStart(task, $event)"
                                    @dragend="kanbanDragEnd()"
                                    @click="toggleKanbanTask(task.id)">
                                    <div class="checklist-kanban-card__top">
                                        <span class="checklist-kanban-card__grip" aria-hidden="true">⋮⋮</span>
                                        <h4 class="checklist-kanban-card__title" x-text="task.title"></h4>
                                        <div class="checklist-kanban-card__actions" @click.stop>
                                            <template x-if="pendingDeleteIds.includes(task.id)">
                                                <button type="button" class="checklist-kanban-card__action" @click="undoDelete(task.id)">Undo</button>
                                            </template>
                                            <template x-if="task.can_delete && !pendingDeleteIds.includes(task.id)">
                                                <button type="button" class="checklist-kanban-card__action checklist-kanban-card__action--danger" @click="markDelete(task.id)">Remove</button>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="checklist-kanban-card__tags">
                                        <span class="checklist-kanban-tag checklist-kanban-tag--phase" x-text="phaseShort(task.phase)"></span>
                                        <span x-show="task.section" class="checklist-kanban-tag checklist-kanban-tag--section" x-text="task.section"></span>
                                    </div>
                                    <div class="checklist-kanban-card__footer">
                                        <div class="checklist-kanban-card__assignee">
                                            <span class="checklist-kanban-avatar"
                                                :class="task.assignee_user_id ? '' : 'checklist-kanban-avatar--empty'"
                                                x-text="assigneeInitials(task)"></span>
                                            <span class="checklist-kanban-card__assignee-name" x-text="assigneeName(task)"></span>
                                        </div>
                                        <span x-show="task.due_date"
                                            class="checklist-kanban-due"
                                            :class="dueDateTone(task.due_date)"
                                            x-text="formatDueDate(task.due_date)"></span>
                                    </div>
                                    <div class="checklist-kanban-card__edit"
                                        x-show="expandedKanbanTaskId === task.id && task.can_edit_assignee && !pendingDeleteIds.includes(task.id)"
                                        x-cloak
                                        @click.stop>
                                        <div class="grid grid-cols-1 gap-2">
                                            <div>
                                                <label class="text-[10px] font-medium text-gray-500 dark:text-gray-400">Assignee</label>
                                                <select class="ta-select w-full text-xs mt-0.5"
                                                    :value="task.assignee_user_id"
                                                    @change="setTaskField(task.id, 'assignee_user_id', $event.target.value)">
                                                    <option value="">— Unassigned —</option>
                                                    <template x-for="person in staff" :key="'k-assign-' + task.id + '-' + person.id">
                                                        <option :value="String(person.id)" x-text="person.first_name + ' ' + person.last_name"></option>
                                                    </template>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="text-[10px] font-medium text-gray-500 dark:text-gray-400">Due date</label>
                                                <input type="date" class="ta-input w-full text-xs mt-0.5"
                                                    :value="task.due_date"
                                                    @input="setTaskField(task.id, 'due_date', $event.target.value)">
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            </template>
                        </div>
                    </section>
                </template>
            </div>
        </div>

        <!-- List view (by event phase) -->
        <div x-show="viewMode === 'list'">
        <?php foreach (['pre', 'day_of', 'post'] as $phaseKey): ?>
        <details class="mb-4 border border-gray-200 rounded-xl dark:border-gray-700" <?= $phaseKey === 'pre' ? 'open' : '' ?>>
            <summary class="cursor-pointer px-4 py-3 font-semibold text-gray-800 flex justify-between dark:text-gray-100">
                <span><?= e($phaseLabels[$phaseKey] ?? $phaseKey) ?></span>
                <span class="text-sm font-normal text-gray-500" x-text="phaseProgress('<?= e($phaseKey) ?>')"></span>
            </summary>
            <div class="px-4 pb-4 space-y-6">
                <template x-if="sectionsInPhase('<?= e($phaseKey) ?>').length === 0">
                    <p class="text-sm text-gray-500 py-2">No tasks in this phase.</p>
                </template>
                <template x-for="section in sectionsInPhase('<?= e($phaseKey) ?>')" :key="'<?= e($phaseKey) ?>-' + section.name">
                    <div>
                        <h3 class="text-sm font-bold text-gray-800 mb-3 dark:text-gray-100" x-text="section.name || 'General'"></h3>
                        <div class="space-y-3">
                            <template x-for="task in section.tasks" :key="task.id">
                                <div class="rounded-xl border p-4 dark:border-gray-700"
                                    :class="pendingDeleteIds.includes(task.id)
                                        ? 'border-red-200 bg-red-50/40 opacity-70 dark:border-red-900 dark:bg-red-950/20'
                                        : (task.status === 'in_progress'
                                            ? 'border-gray-200 bg-amber-50/50 dark:bg-amber-900/10'
                                            : 'border-gray-200 bg-white dark:bg-gray-800/30')">
                                    <div class="flex items-start justify-between gap-2 mb-3">
                                        <p class="font-medium text-gray-900 dark:text-gray-100" x-text="task.title"></p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <template x-if="pendingDeleteIds.includes(task.id)">
                                                <button type="button" @click="undoDelete(task.id)" class="text-xs text-brand-600 hover:underline">Undo remove</button>
                                            </template>
                                            <template x-if="task.can_delete && !pendingDeleteIds.includes(task.id)">
                                                <button type="button" @click="markDelete(task.id)" class="text-xs text-red-600 hover:underline">Remove</button>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Status</label>
                                            <select class="ta-select w-full text-sm"
                                                :value="task.status"
                                                @change="setTaskField(task.id, 'status', $event.target.value)"
                                                :disabled="!task.can_edit || pendingDeleteIds.includes(task.id)">
                                                <option value="not_started">Not started</option>
                                                <option value="in_progress">In progress</option>
                                                <option value="complete">Complete</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Assigned lead</label>
                                            <select class="ta-select w-full text-sm"
                                                :value="task.assignee_user_id"
                                                @change="setTaskField(task.id, 'assignee_user_id', $event.target.value)"
                                                :disabled="!task.can_edit_assignee || pendingDeleteIds.includes(task.id)">
                                                <option value="">— Unassigned —</option>
                                                <template x-for="person in staff" :key="'a-' + task.id + '-' + person.id">
                                                    <option :value="String(person.id)" x-text="person.first_name + ' ' + person.last_name"></option>
                                                </template>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Due date</label>
                                            <input type="date" class="ta-input w-full text-sm"
                                                :value="task.due_date"
                                                @input="setTaskField(task.id, 'due_date', $event.target.value)"
                                                :disabled="!task.can_edit_assignee || pendingDeleteIds.includes(task.id)">
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <?php if ($canManage): ?>
                <button type="button" @click="openAddForm('<?= e($phaseKey) ?>')"
                    class="text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400">+ Add task to this phase</button>
                <?php endif; ?>
            </div>
        </details>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Sticky save bar (teleport to body so it isn't clipped by scroll containers) -->
    <template x-teleport="body">
        <div x-show="hasUnsavedChanges"
            class="fixed bottom-0 left-0 right-0 z-[99990] border-t border-brand-200 bg-white/95 backdrop-blur px-4 py-3 shadow-lg dark:border-brand-900 dark:bg-gray-900/95">
            <div class="max-w-5xl mx-auto flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-semibold text-brand-700 dark:text-brand-300">Unsaved changes</span>
                    — update statuses, assignments, or due dates, then save.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="discardChanges()" class="btn-secondary text-sm" :disabled="saving">Discard</button>
                    <button type="button" @click="saveAll()" class="btn-primary text-sm" :disabled="saving">
                        <span x-text="saving ? 'Saving…' : 'Save changes'"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>
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
            userId: cfg.userId || 0,
            items: [],
            baselineJson: '',
            pendingDeleteIds: [],
            hasUnsavedChanges: false,
            saving: false,
            showAddForm: false,
            showPicker: false,
            pickerMode: 'generate',
            pickerLoading: false,
            pickerSubmitting: false,
            pickerTasks: [],
            pickerSelected: {},
            pickerTemplateName: '',
            pickerAddedIds: [],
            viewMode: 'list',
            kanbanColumns: [
                { key: 'not_started', label: 'Not started' },
                { key: 'in_progress', label: 'In progress' },
                { key: 'complete', label: 'Complete' },
            ],
            kanbanDragTaskId: null,
            kanbanDropTarget: null,
            kanbanJustDragged: false,
            expandedKanbanTaskId: null,
            normalizeDueDate: function(val) {
                if (val === null || val === undefined || val === '') {
                    return '';
                }
                return String(val).substring(0, 10);
            },
            normalizeItems: function(list) {
                var self = this;
                return (list || []).map(function(item) {
                    return Object.assign({}, item, {
                        assignee_user_id: item.assignee_user_id === '' || item.assignee_user_id === null || item.assignee_user_id === undefined
                            ? '' : String(item.assignee_user_id),
                        due_date: self.normalizeDueDate(item.due_date),
                    });
                });
            },
            cloneItems: function(list) {
                return JSON.parse(JSON.stringify(list || []));
            },
            snapshotItems: function() {
                var self = this;
                return this.items.map(function(item) {
                    return {
                        id: item.id,
                        status: item.status,
                        assignee_user_id: item.assignee_user_id === '' || item.assignee_user_id === null ? '' : String(item.assignee_user_id),
                        due_date: self.normalizeDueDate(item.due_date),
                        deleted: self.pendingDeleteIds.includes(item.id),
                    };
                });
            },
            isDirty: function() {
                return this.hasUnsavedChanges;
            },
            syncDirtyState: function() {
                var self = this;
                this.$nextTick(function() {
                    self.hasUnsavedChanges = JSON.stringify(self.snapshotItems()) !== self.baselineJson;
                });
            },
            setTaskField: function(taskId, field, value) {
                var task = this.items.find(function(t) { return t.id === taskId; });
                if (!task) {
                    return;
                }
                if (field === 'assignee_user_id') {
                    task.assignee_user_id = value === '' || value === null ? '' : String(value);
                } else if (field === 'due_date') {
                    task.due_date = this.normalizeDueDate(value);
                } else {
                    task[field] = value;
                }
                this.syncDirtyState();
            },
            progressStats: function() {
                var visible = this.items.filter(function(i) {
                    return !this.pendingDeleteIds.includes(i.id);
                }, this);
                var total = visible.length;
                var complete = visible.filter(function(i) { return i.status === 'complete'; }).length;
                var inProgress = visible.filter(function(i) { return i.status === 'in_progress'; }).length;
                var pct = total > 0 ? Math.round((complete / total) * 100) : 0;
                return { total: total, complete: complete, in_progress: inProgress, pct: pct };
            },
            phaseProgress: function(phaseKey) {
                var visible = this.items.filter(function(i) {
                    return i.phase === phaseKey && !this.pendingDeleteIds.includes(i.id);
                }, this);
                var complete = visible.filter(function(i) { return i.status === 'complete'; }).length;
                return complete + '/' + visible.length;
            },
            itemsInPhase: function(phaseKey) {
                return this.items.filter(function(i) {
                    return i.phase === phaseKey && !this.pendingDeleteIds.includes(i.id);
                });
            },
            sectionsInPhase: function(phaseKey) {
                var tasks = this.items.filter(function(i) {
                    return i.phase === phaseKey;
                });
                var sections = {};
                tasks.forEach(function(t) {
                    var name = t.section || 'General';
                    if (!sections[name]) {
                        sections[name] = { name: name, tasks: [] };
                    }
                    sections[name].tasks.push(t);
                });
                return Object.values(sections);
            },
            setViewMode: function(mode) {
                this.viewMode = mode === 'kanban' ? 'kanban' : 'list';
                try {
                    localStorage.setItem('event-checklist-view', this.viewMode);
                } catch (e) { /* ignore */ }
            },
            itemsByStatus: function(status) {
                return this.items.filter(function(i) {
                    return i.status === status && !this.pendingDeleteIds.includes(i.id);
                }, this);
            },
            canKanbanDrag: function(task) {
                return !!(task && task.can_edit && !this.pendingDeleteIds.includes(task.id));
            },
            phaseShort: function(phase) {
                var map = { pre: 'Pre-event', day_of: 'Day-of', post: 'Post-event' };
                return map[phase] || phase;
            },
            assigneeName: function(task) {
                if (!task || !task.assignee_user_id) {
                    return 'Unassigned';
                }
                var p = this.staff.find(function(s) {
                    return String(s.id) === String(task.assignee_user_id);
                });
                return p ? (p.first_name + ' ' + p.last_name) : (task.assignee_label || 'Unassigned');
            },
            assigneeInitials: function(task) {
                if (!task || !task.assignee_user_id) {
                    return '?';
                }
                var name = this.assigneeName(task);
                if (!name || name === 'Unassigned') {
                    return '?';
                }
                var parts = name.split(' ').filter(function(part) { return part !== ''; });
                if (parts.length >= 2) {
                    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
                }
                return name.substring(0, 2).toUpperCase();
            },
            formatDueDate: function(val) {
                var d = this.normalizeDueDate(val);
                if (!d) {
                    return '';
                }
                var parts = d.split('-');
                if (parts.length !== 3) {
                    return d;
                }
                var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                return months[parseInt(parts[1], 10) - 1] + ' ' + parseInt(parts[2], 10);
            },
            dueDateTone: function(val) {
                var d = this.normalizeDueDate(val);
                if (!d) {
                    return '';
                }
                var today = new Date();
                today.setHours(0, 0, 0, 0);
                var due = new Date(d + 'T00:00:00');
                if (isNaN(due.getTime())) {
                    return '';
                }
                var diff = Math.ceil((due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
                if (diff < 0) {
                    return 'checklist-kanban-due--overdue';
                }
                if (diff <= 3) {
                    return 'checklist-kanban-due--soon';
                }
                return '';
            },
            toggleKanbanTask: function(taskId) {
                if (this.kanbanJustDragged) {
                    return;
                }
                this.expandedKanbanTaskId = this.expandedKanbanTaskId === taskId ? null : taskId;
            },
            kanbanDragStart: function(task, e) {
                if (!this.canKanbanDrag(task)) {
                    e.preventDefault();
                    return;
                }
                this.kanbanDragTaskId = task.id;
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', String(task.id));
                }
            },
            kanbanDragEnd: function() {
                var self = this;
                this.kanbanJustDragged = true;
                setTimeout(function() { self.kanbanJustDragged = false; }, 150);
                this.kanbanDragTaskId = null;
                this.kanbanDropTarget = null;
            },
            kanbanDragOver: function(status) {
                this.kanbanDropTarget = status;
            },
            kanbanDragLeave: function(status, e) {
                if (e.currentTarget && e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) {
                    return;
                }
                if (this.kanbanDropTarget === status) {
                    this.kanbanDropTarget = null;
                }
            },
            kanbanDrop: function(status, e) {
                this.kanbanDropTarget = null;
                var id = parseInt(e.dataTransfer.getData('text/plain'), 10);
                if (!id) {
                    return;
                }
                var task = this.items.find(function(t) { return t.id === id; });
                if (!task || !this.canKanbanDrag(task)) {
                    return;
                }
                if (task.status !== status) {
                    task.status = status;
                    this.syncDirtyState();
                }
                this.kanbanDragTaskId = null;
            },
            markDelete: function(itemId) {
                if (!confirm('Mark this task for removal? Click Save changes to confirm, or Discard to undo.')) {
                    return;
                }
                if (!this.pendingDeleteIds.includes(itemId)) {
                    this.pendingDeleteIds.push(itemId);
                }
                this.syncDirtyState();
            },
            undoDelete: function(itemId) {
                this.pendingDeleteIds = this.pendingDeleteIds.filter(function(id) { return id !== itemId; });
                this.syncDirtyState();
            },
            discardChanges: function() {
                if (!confirm('Discard all unsaved checklist changes?')) {
                    return;
                }
                var baselineRows = JSON.parse(this.baselineJson || '[]');
                this.items = this.normalizeItems(this.cloneItems(cfg.initialItems || [])).map(function(item) {
                    var row = baselineRows.find(function(b) { return b.id === item.id; });
                    if (!row) {
                        return item;
                    }
                    return Object.assign({}, item, {
                        status: row.status,
                        assignee_user_id: row.assignee_user_id,
                        due_date: row.due_date,
                    });
                });
                this.pendingDeleteIds = [];
                this.hasUnsavedChanges = false;
            },
            buildUpdatesPayload: function() {
                var baseline = JSON.parse(this.baselineJson);
                var updates = [];
                var self = this;
                this.items.forEach(function(item) {
                    if (self.pendingDeleteIds.includes(item.id)) {
                        return;
                    }
                    var base = baseline.find(function(b) { return b.id === item.id; });
                    if (!base) {
                        return;
                    }
                    var patch = { item_id: item.id };
                    var changed = false;
                    if (item.can_edit && item.status !== base.status) {
                        patch.status = item.status;
                        changed = true;
                    }
                    if (item.can_edit_assignee) {
                        var assignee = item.assignee_user_id === '' || item.assignee_user_id === null
                            ? null
                            : parseInt(item.assignee_user_id, 10);
                        if (isNaN(assignee)) {
                            assignee = null;
                        }
                        var baseAssignee = base.assignee_user_id === '' ? null : parseInt(base.assignee_user_id, 10);
                        if (isNaN(baseAssignee)) {
                            baseAssignee = null;
                        }
                        if (assignee !== baseAssignee) {
                            patch.assignee_user_id = assignee;
                            changed = true;
                        }
                        var due = self.normalizeDueDate(item.due_date) || null;
                        var baseDue = self.normalizeDueDate(base.due_date) || null;
                        if (due !== baseDue) {
                            patch.due_date = due;
                            changed = true;
                        }
                    }
                    if (changed) {
                        updates.push(patch);
                    }
                });
                return updates;
            },
            saveAll: async function() {
                if (!this.hasUnsavedChanges) {
                    return;
                }
                this.saving = true;
                try {
                    var updates = this.buildUpdatesPayload();
                    var res = await fetch(this.apiBase, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            action: 'save_items',
                            event_id: this.eventId,
                            updates: updates,
                            delete_ids: this.pendingDeleteIds.slice(),
                        })
                    });
                    var data = {};
                    try { data = await res.json(); } catch (e) { /* ignore */ }
                    if (!res.ok || !data.success) {
                        var msg = data.message || ('Failed to save changes (HTTP ' + res.status + ').');
                        if (res.status === 400 && msg === 'Unknown action') {
                            msg = 'Save is not available on the server yet. Deploy the latest event-checklist API files.';
                        }
                        alert(msg);
                        console.error('Checklist save failed', { status: res.status, data: data, updates: updates });
                        return;
                    }
                    location.reload();
                } finally {
                    this.saving = false;
                }
            },
            init: function() {
                try {
                    var savedView = localStorage.getItem('event-checklist-view');
                    if (savedView === 'kanban' || savedView === 'list') {
                        this.viewMode = savedView;
                    }
                } catch (e) { /* ignore */ }
                this.items = this.normalizeItems(this.cloneItems(cfg.initialItems || []));
                this.baselineJson = JSON.stringify(this.snapshotItems());
                this.hasUnsavedChanges = false;
                var self = this;
                this.$watch('pendingDeleteIds', function() { self.syncDirtyState(); }, { deep: true });
                if (cfg.openPicker) {
                    this.openTaskPicker('generate');
                }
                window.addEventListener('beforeunload', function(e) {
                    if (self.hasUnsavedChanges) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });
            },
            pickerTitle: function() {
                if (this.pickerMode === 'replace') return 'Replace checklist tasks';
                if (this.pickerMode === 'merge') return 'Add tasks from template';
                return 'Choose checklist tasks';
            },
            pickerConfirmLabel: function() {
                if (this.pickerMode === 'replace') return 'Replace with selected';
                if (this.pickerMode === 'merge') return 'Add selected tasks';
                return 'Add selected tasks';
            },
            pickerSelectedCount: function() {
                var self = this;
                return Object.keys(this.pickerSelected).filter(function(id) { return self.pickerSelected[id]; }).length;
            },
            pickerTasksByPhase: function(phaseKey) {
                return this.pickerTasks.filter(function(t) { return t.phase === phaseKey; });
            },
            pickerSectionsInPhase: function(phaseKey) {
                var tasks = this.pickerTasksByPhase(phaseKey);
                var sections = {};
                tasks.forEach(function(t) {
                    var name = t.section || 'General';
                    if (!sections[name]) sections[name] = { name: name, tasks: [] };
                    sections[name].tasks.push(t);
                });
                return Object.values(sections);
            },
            togglePickerTask: function(id, checked) {
                this.pickerSelected[id] = !!checked;
            },
            pickerSelectAll: function(on) {
                var self = this;
                this.pickerTasks.forEach(function(t) {
                    if (self.pickerMode === 'merge' && t.already_added) return;
                    self.pickerSelected[t.id] = !!on;
                });
            },
            pickerSelectPhase: function(phaseKey, on) {
                var self = this;
                this.pickerTasksByPhase(phaseKey).forEach(function(t) {
                    if (self.pickerMode === 'merge' && t.already_added) return;
                    self.pickerSelected[t.id] = !!on;
                });
            },
            openTaskPicker: async function(mode) {
                if (this.isDirty()) {
                    alert('Save or discard your checklist edits before adding template tasks.');
                    return;
                }
                this.pickerMode = mode || 'generate';
                if (mode === 'replace') {
                    if (!confirm('This removes all current checklist tasks for this event, then adds the tasks you select. Continue?')) {
                        return;
                    }
                }
                this.showPicker = true;
                this.pickerLoading = true;
                this.pickerTasks = [];
                this.pickerSelected = {};
                try {
                    const res = await fetch(this.apiBase + '?action=template_preview&event_id=' + this.eventId);
                    const data = await res.json();
                    if (!data.success) {
                        alert(data.message || 'Could not load template tasks.');
                        this.showPicker = false;
                        return;
                    }
                    var added = (data.added_task_ids || []).map(Number);
                    this.pickerAddedIds = added;
                    this.pickerTemplateName = data.template_name || 'Template';
                    this.pickerTasks = (data.tasks || []).map(function(t) {
                        t.id = parseInt(t.id, 10);
                        t.already_added = added.indexOf(t.id) !== -1;
                        return t;
                    });
                    var self = this;
                    this.pickerTasks.forEach(function(t) {
                        if (mode === 'merge') {
                            self.pickerSelected[t.id] = !t.already_added;
                        } else {
                            self.pickerSelected[t.id] = true;
                        }
                    });
                } catch (e) {
                    alert('Could not load template tasks.');
                    this.showPicker = false;
                } finally {
                    this.pickerLoading = false;
                }
            },
            confirmTaskPicker: async function() {
                var self = this;
                var ids = Object.keys(this.pickerSelected).filter(function(id) {
                    return self.pickerSelected[id];
                }).map(function(id) { return parseInt(id, 10); });
                if (!ids.length) {
                    alert('Select at least one task.');
                    return;
                }
                this.pickerSubmitting = true;
                try {
                    var action = this.pickerMode === 'replace' ? 'replace_template' : 'generate';
                    var body = { action: action, event_id: this.eventId, task_ids: ids, notify: true };
                    if (this.pickerMode === 'merge') body.merge = true;
                    const res = await fetch(this.apiBase, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body)
                    });
                    let data = {};
                    try { data = await res.json(); } catch (e) { /* ignore */ }
                    if (!res.ok || !data.success) {
                        alert(data.message || 'Failed to add tasks.');
                        return;
                    }
                    location.reload();
                } finally {
                    this.pickerSubmitting = false;
                }
            },
            addForm: { phase: 'pre', title: '', section: 'Custom', role_id: '', assignee_user_id: '', due_date: '' },
            openAddForm: function(phase) {
                this.addForm = { phase: phase || 'pre', title: '', section: 'Custom', role_id: '', assignee_user_id: '', due_date: '' };
                this.showAddForm = true;
                window.scrollTo({ top: document.querySelector('[x-show="showAddForm"]')?.offsetTop || 0, behavior: 'smooth' });
            },
            addTask: async function() {
                if (this.isDirty()) {
                    alert('Save or discard your checklist edits before adding a custom task.');
                    return;
                }
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
            markDone: async function() {
                if (this.isDirty()) {
                    alert('Save or discard your checklist edits first.');
                    return;
                }
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
                if (this.isDirty()) {
                    alert('Save or discard your checklist edits first.');
                    return;
                }
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reopen_event', event_id: this.eventId })
                });
                const data = await res.json();
                if (data.success) location.reload();
                else alert(data.message || 'Failed');
            },
        };
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
