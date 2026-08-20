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
$staffIds = [];
foreach ($staff as $s) {
    $staffIds[(int) $s['id']] = true;
}

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
        'can_edit_status' => $canManage || $assigneeId === $userId,
        'can_delete' => $canManage,
    ];
}

require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in space-y-6 pb-6" x-data="eventChecklistPage(<?= htmlspecialchars(json_encode([
    'eventId' => $eventId,
    'apiBase' => $apiBase,
    'canManage' => $canManage,
    'canEditAll' => $canManage,
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
    <template x-if="hasItems">
        <button type="button" @click="saveUpdates()" class="btn-primary text-sm" :disabled="saving">
            <span x-text="saving ? 'Saving…' : 'Save updates'"></span>
        </button>
    </template>
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
                    Assign leads, update status, or due dates — each change saves immediately. You can also click <strong>Save updates</strong>.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <?php if ($canManage): ?>
                <button type="button" @click="openAddForm('pre')" class="btn-secondary text-sm">+ Add custom task</button>
                <?php if ($hasChecklistItems): ?>
                <button type="button" @click="openTaskPicker('merge')" class="btn-secondary text-sm">Add from template</button>
                <?php endif; ?>
                <?php endif; ?>
                <button type="button" x-show="hasItems" @click="saveUpdates()" class="btn-primary text-sm" :disabled="saving">
                    <span x-text="saving ? 'Saving…' : 'Save updates'"></span>
                </button>
            </div>
        </div>

        <div x-show="saveMessage" x-cloak
            class="mb-4 rounded-xl border px-4 py-3 text-sm"
            :class="saveError
                ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200'
                : 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200'"
            x-text="saveMessage"></div>

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
                        <?php foreach ($roles as $role): ?>
                        <option value="<?= (int) $role['id'] ?>"><?= e((string) ($role['label'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Assign to (optional)</label>
                    <select x-model="addForm.assignee_user_id" class="ta-select w-full mt-1">
                        <option value="">— Auto from role —</option>
                        <?php foreach ($staff as $person): ?>
                        <option value="<?= (int) $person['id'] ?>"><?= e(trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''))) ?></option>
                        <?php endforeach; ?>
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

        <!-- Task list (by event phase) -->
        <?php foreach (['pre', 'day_of', 'post'] as $phaseKey): ?>
        <details class="mb-4 border border-gray-200 rounded-xl dark:border-gray-700" <?= $phaseKey === 'pre' ? 'open' : '' ?>>
            <summary class="cursor-pointer px-4 py-3 font-semibold text-gray-800 flex justify-between dark:text-gray-100">
                <span><?= e($phaseLabels[$phaseKey] ?? $phaseKey) ?></span>
                <span class="text-sm font-normal text-gray-500" x-text="phaseProgress('<?= e($phaseKey) ?>')"></span>
            </summary>
            <div class="px-4 pb-4 space-y-6 overflow-visible">
                <?php
                $phaseSections = $grouped[$phaseKey] ?? [];
                if ($phaseSections === []):
                ?>
                <p class="text-sm text-gray-500 py-2">No tasks in this phase.</p>
                <?php else: ?>
                <?php foreach ($phaseSections as $sectionName => $sectionItems): ?>
                    <div>
                        <h3 class="text-sm font-bold text-gray-800 mb-3 dark:text-gray-100"><?= e($sectionName !== '' ? (string) $sectionName : 'General') ?></h3>
                        <div class="space-y-3">
                            <?php foreach ($sectionItems as $it):
                                $tid = (int) ($it['id'] ?? 0);
                                $assigneeId = !empty($it['assignee_user_id']) ? (int) $it['assignee_user_id'] : 0;
                                $statusVal = (string) ($it['status'] ?? 'not_started');
                                $dueRaw = $it['due_date'] ?? '';
                                $dueVal = $dueRaw !== '' && $dueRaw !== null ? substr((string) $dueRaw, 0, 10) : '';
                                $assigneeLabel = trim(($it['assignee_first_name'] ?? '') . ' ' . ($it['assignee_last_name'] ?? ''));
                                $inProgress = $statusVal === 'in_progress';
                            ?>
                                <div class="rounded-xl border p-4 dark:border-gray-700 <?= $inProgress ? 'border-gray-200 bg-amber-50/50 dark:bg-amber-900/10' : 'border-gray-200 bg-white dark:bg-gray-800/30' ?>"
                                    :class="pendingDeleteIds.includes(<?= $tid ?>) ? 'border-red-200 bg-red-50/40 opacity-70 dark:border-red-900 dark:bg-red-950/20' : ''">
                                    <div class="flex items-start justify-between gap-2 mb-3">
                                        <p class="font-medium text-gray-900 dark:text-gray-100"><?= e((string) ($it['title'] ?? '')) ?></p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span x-show="pendingDeleteIds.includes(<?= $tid ?>)" x-cloak class="text-xs font-medium text-red-600">Will remove on save</span>
                                            <button type="button" x-show="pendingDeleteIds.includes(<?= $tid ?>)" x-cloak @click="undoDelete(<?= $tid ?>)" class="text-xs text-brand-600 hover:underline">Undo</button>
                                            <?php if ($canManage): ?>
                                            <button type="button" x-show="!pendingDeleteIds.includes(<?= $tid ?>)" @click="markDelete(<?= $tid ?>)" class="text-xs text-red-600 hover:underline">Remove</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Status</label>
                                            <select class="ta-select w-full text-sm"
                                                @change="onTaskChange(<?= $tid ?>, 'status', $event.target.value)"
                                                <?= $canManage ? '' : 'disabled' ?>>
                                                <option value="not_started" <?= $statusVal === 'not_started' ? 'selected' : '' ?>>Not started</option>
                                                <option value="in_progress" <?= $statusVal === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                                                <option value="complete" <?= $statusVal === 'complete' ? 'selected' : '' ?>>Complete</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Assigned lead</label>
                                            <select class="ta-select w-full text-sm"
                                                @change="onTaskChange(<?= $tid ?>, 'assignee_user_id', $event.target.value)"
                                                <?= $canManage ? '' : 'disabled' ?>>
                                                <option value="" <?= $assigneeId <= 0 ? 'selected' : '' ?>>— Unassigned —</option>
                                                <?php foreach ($staff as $person):
                                                    $pid = (int) ($person['id'] ?? 0);
                                                    if ($pid <= 0) {
                                                        continue;
                                                    }
                                                ?>
                                                <option value="<?= $pid ?>" <?= $assigneeId === $pid ? 'selected' : '' ?>><?= e(trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''))) ?></option>
                                                <?php endforeach; ?>
                                                <?php if ($assigneeId > 0 && empty($staffIds[$assigneeId]) && $assigneeLabel !== ''): ?>
                                                <option value="<?= $assigneeId ?>" selected><?= e($assigneeLabel) ?></option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Due date</label>
                                            <input type="date" class="ta-input w-full text-sm" value="<?= e($dueVal) ?>"
                                                @change="onTaskChange(<?= $tid ?>, 'due_date', $event.target.value)"
                                                <?= $canManage ? '' : 'disabled' ?>>
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

        <div x-show="hasItems" class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
            <button type="button" @click="saveUpdates()" class="btn-primary" :disabled="saving">
                <span x-text="saving ? 'Saving…' : 'Save updates'"></span>
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', function() {
    Alpine.data('eventChecklistPage', function(cfg) {
        return {
            eventId: cfg.eventId,
            apiBase: cfg.apiBase,
            canManage: cfg.canManage,
            canEditAll: !!cfg.canEditAll,
            canManageEvents: cfg.canManageEvents,
            status: cfg.status,
            hasItems: !!cfg.hasItems,
            phaseLabels: cfg.phaseLabels || {},
            roles: cfg.roles || [],
            staff: cfg.staff || [],
            userId: cfg.userId || 0,
            items: [],
            pendingDeleteIds: [],
            saving: false,
            saveMessage: '',
            saveError: false,
            showAddForm: false,
            showPicker: false,
            pickerMode: 'generate',
            pickerLoading: false,
            pickerSubmitting: false,
            pickerTasks: [],
            pickerSelected: {},
            pickerTemplateName: '',
            pickerAddedIds: [],
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
            getTaskValue: function(taskId, field) {
                var task = this.items.find(function(t) { return t.id === taskId; });
                if (!task) {
                    return '';
                }
                if (field === 'assignee_user_id') {
                    return task.assignee_user_id === null || task.assignee_user_id === undefined ? '' : String(task.assignee_user_id);
                }
                if (field === 'due_date') {
                    return this.normalizeDueDate(task.due_date);
                }
                return task[field] || '';
            },
            isFieldDisabled: function(task, kind) {
                if (!task) {
                    return true;
                }
                if (this.canEditAll || this.canManage) {
                    return false;
                }
                if (kind === 'status') {
                    return !task.can_edit_status && !task.can_edit;
                }
                return !task.can_edit_assignee;
            },
            progressStats: function() {
                var visible = this.items;
                var total = visible.length;
                var complete = visible.filter(function(i) { return i.status === 'complete'; }).length;
                var inProgress = visible.filter(function(i) { return i.status === 'in_progress'; }).length;
                var pct = total > 0 ? Math.round((complete / total) * 100) : 0;
                return { total: total, complete: complete, in_progress: inProgress, pct: pct };
            },
            phaseProgress: function(phaseKey) {
                var visible = this.items.filter(function(i) {
                    return i.phase === phaseKey;
                });
                var complete = visible.filter(function(i) { return i.status === 'complete'; }).length;
                return complete + '/' + visible.length;
            },
            itemsInPhase: function(phaseKey) {
                return this.items.filter(function(i) {
                    return i.phase === phaseKey;
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
            onTaskChange: async function(taskId, field, rawValue) {
                var task = this.items.find(function(t) { return t.id === taskId; });
                if (!task) {
                    return;
                }
                if (field === 'assignee_user_id') {
                    task.assignee_user_id = rawValue === '' || rawValue === null ? '' : String(rawValue);
                } else if (field === 'due_date') {
                    task.due_date = this.normalizeDueDate(rawValue);
                } else {
                    task[field] = rawValue;
                }
                this.saveMessage = '';
                this.saveError = false;

                var payload = {
                    action: 'update_item',
                    event_id: this.eventId,
                    item_id: taskId,
                };
                if (field === 'assignee_user_id') {
                    var assignee = parseInt(task.assignee_user_id, 10);
                    payload.assignee_user_id = (!task.assignee_user_id || isNaN(assignee) || assignee <= 0) ? null : assignee;
                } else if (field === 'due_date') {
                    payload.due_date = task.due_date || null;
                } else {
                    payload[field] = task[field];
                }

                this.saving = true;
                try {
                    var res = await fetch(this.apiBase, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    var data = {};
                    try { data = await res.json(); } catch (e) { /* ignore */ }
                    if (!res.ok || !data.success) {
                        this.saveError = true;
                        this.saveMessage = data.message || 'Could not save that change.';
                        return;
                    }
                    this.saveError = false;
                    this.saveMessage = 'Saved.';
                } catch (e) {
                    this.saveError = true;
                    this.saveMessage = 'Could not save that change.';
                } finally {
                    this.saving = false;
                }
            },
            buildUpdatesPayload: function() {
                var self = this;
                return this.items.filter(function(item) {
                    return !self.pendingDeleteIds.includes(item.id);
                }).map(function(item) {
                    var assignee = item.assignee_user_id === '' || item.assignee_user_id === null
                        ? null
                        : parseInt(item.assignee_user_id, 10);
                    if (isNaN(assignee) || assignee <= 0) {
                        assignee = null;
                    }
                    return {
                        item_id: item.id,
                        status: item.status,
                        assignee_user_id: assignee,
                        due_date: self.normalizeDueDate(item.due_date) || null,
                    };
                });
            },
            saveUpdates: async function() {
                if (!this.items.length) {
                    return;
                }
                this.saving = true;
                this.saveMessage = '';
                this.saveError = false;
                try {
                    var res = await fetch(this.apiBase, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            action: 'save_items',
                            event_id: this.eventId,
                            updates: this.buildUpdatesPayload(),
                            delete_ids: this.pendingDeleteIds.slice(),
                        }),
                    });
                    var data = {};
                    try { data = await res.json(); } catch (e) { /* ignore */ }
                    if (!res.ok || !data.success) {
                        this.saveError = true;
                        this.saveMessage = data.message || ('Could not save updates (HTTP ' + res.status + ').');
                        return;
                    }
                    this.pendingDeleteIds = [];
                    this.saveError = false;
                    this.saveMessage = 'Checklist updates saved.';
                    location.reload();
                } catch (e) {
                    this.saveError = true;
                    this.saveMessage = 'Could not save updates.';
                } finally {
                    this.saving = false;
                }
            },
            markDelete: function(itemId) {
                if (!confirm('Remove this task? Click Save updates to confirm.')) {
                    return;
                }
                if (!this.pendingDeleteIds.includes(itemId)) {
                    this.pendingDeleteIds.push(itemId);
                }
            },
            undoDelete: function(itemId) {
                this.pendingDeleteIds = this.pendingDeleteIds.filter(function(id) { return id !== itemId; });
            },
            init: function() {
                this.items = this.normalizeItems(this.cloneItems(cfg.initialItems || []));
                if (cfg.openPicker) {
                    this.openTaskPicker('generate');
                }
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
        };
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
