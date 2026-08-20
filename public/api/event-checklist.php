<?php

/**
 * Event checklist API — leadership, tasks, templates, my-tasks.
 * GET/POST /api/event-checklist.php?action=...
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
use Headcount\Services\EventChecklistService;

header('Content-Type: application/json');

function checklistJson($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

try {
    $config = require HC_PROJECT_ROOT . '/config/config.php';
    Database::getInstance($config['database']);

    AuthMiddleware::requireAdminOrCoordinator();
    AuthMiddleware::requireCan('checklists.view');

    $organizationId = (int) AuthMiddleware::getOrganizationId();
    $userId = (int) AuthMiddleware::getUserId();
    $isSuperAdmin = AuthMiddleware::isSuperAdmin();
    $canManageTemplates = AuthMiddleware::can('checklists.manage_templates') && AuthMiddleware::can('settings.access');

    $db = Database::getInstance();
    $svc = new EventChecklistService($db);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = [];
    if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : $_POST;
    }

    $action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? '')));

    switch ($action) {
        case 'staff':
            $q = trim((string) ($_GET['q'] ?? ''));
            checklistJson([
                'success' => true,
                'staff' => $svc->listEligibleAssignees($organizationId, $q !== '' ? $q : null),
            ]);

        case 'roles':
            checklistJson(['success' => true, 'roles' => $svc->listRoles($organizationId)]);

        case 'leadership':
            $eventId = (int) ($_GET['event_id'] ?? $input['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            $event = $db->queryOne(
                'SELECT id, parent_event_id FROM events WHERE id = :id AND organization_id = :oid',
                ['id' => $eventId, 'oid' => $organizationId]
            );
            if (!$event) {
                checklistJson(['success' => false, 'message' => 'Event not found'], 404);
            }
            $storageId = EventChecklistService::storageEventId($event);

            if ($method === 'GET') {
                checklistJson([
                    'success' => true,
                    'leadership' => $svc->getLeadership($storageId),
                ]);
            }

            if (!AuthMiddleware::can('events.manage') && !$svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin)) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }

            $assignments = $input['assignments'] ?? [];
            if (!is_array($assignments)) {
                checklistJson(['success' => false, 'message' => 'Invalid assignments'], 400);
            }
            $result = $svc->setLeadership($storageId, $organizationId, $assignments);
            if (!$result['ok']) {
                checklistJson(['success' => false, 'message' => $result['error'] ?? 'Failed'], 400);
            }
            checklistJson(['success' => true, 'leadership' => $svc->getLeadership($storageId)]);

        case 'items':
            $eventId = (int) ($_GET['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            $items = $svc->listItemsForEvent($eventId, $organizationId);
            $progress = $svc->progressForEvent($eventId, $organizationId);
            $event = $db->queryOne(
                'SELECT id, title, event_date, status, location, category, parent_event_id'
                . ($db->hasColumn('events', 'target_attendance') ? ', target_attendance, budget' : '')
                . ' FROM events WHERE id = :id AND organization_id = :oid',
                ['id' => $eventId, 'oid' => $organizationId]
            );
            if (!$event) {
                checklistJson(['success' => false, 'message' => 'Event not found'], 404);
            }
            $storageId = EventChecklistService::storageEventId($event);
            $canManage = $svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin);
            checklistJson([
                'success' => true,
                'event' => $event,
                'storage_event_id' => $storageId,
                'items' => $items,
                'progress' => $progress,
                'leadership' => $svc->getLeadership($storageId),
                'can_manage' => $canManage,
            ]);

        case 'update_item':
            $itemId = (int) ($input['item_id'] ?? 0);
            if ($itemId <= 0) {
                checklistJson(['success' => false, 'message' => 'item_id required'], 400);
            }
            $eventId = (int) ($input['event_id'] ?? 0);
            $canManageEvents = AuthMiddleware::can('events.manage');
            $canManage = $eventId > 0 && $svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin, $canManageEvents);
            $result = $svc->updateItem($itemId, $organizationId, $userId, $input, $canManage);
            if (!$result['ok']) {
                checklistJson(['success' => false, 'message' => $result['error'] ?? 'Failed'], 400);
            }
            checklistJson(['success' => true]);

        case 'save_items':
            $eventId = (int) ($input['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            $canManageEvents = AuthMiddleware::can('events.manage');
            $updates = isset($input['updates']) && is_array($input['updates']) ? $input['updates'] : [];
            $deleteIds = isset($input['delete_ids']) && is_array($input['delete_ids'])
                ? array_values(array_filter(array_map('intval', $input['delete_ids'])))
                : [];
            $result = $svc->bulkSaveChecklistItems(
                $eventId,
                $organizationId,
                $userId,
                $isSuperAdmin,
                $updates,
                $deleteIds,
                $canManageEvents
            );
            if (!$result['ok']) {
                checklistJson(['success' => false, 'message' => $result['error'] ?? 'Save failed'], 400);
            }
            checklistJson([
                'success' => true,
                'updated' => $result['updated'] ?? 0,
                'deleted' => $result['deleted'] ?? 0,
            ]);

        case 'add_item':
            $eventId = (int) ($input['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            if (!$svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin)) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $result = $svc->addCustomItem($eventId, $organizationId, $input);
            if (!$result['ok']) {
                checklistJson(['success' => false, 'message' => $result['error'] ?? 'Failed'], 400);
            }
            checklistJson(['success' => true, 'id' => $result['id'] ?? null]);

        case 'delete_item':
            $itemId = (int) ($input['item_id'] ?? 0);
            $eventId = (int) ($input['event_id'] ?? 0);
            if ($itemId <= 0 || $eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'item_id and event_id required'], 400);
            }
            $canManageEvents = AuthMiddleware::can('events.manage');
            if (!$svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin, $canManageEvents)) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $result = $svc->deleteItem($itemId, $organizationId);
            checklistJson(['success' => $result['ok'], 'message' => $result['error'] ?? null]);

        case 'generate':
            $eventId = (int) ($input['event_id'] ?? $_GET['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            if (!AuthMiddleware::can('events.manage') && !$svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin)) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $taskIds = null;
            if (array_key_exists('task_ids', $input)) {
                $taskIds = is_array($input['task_ids'])
                    ? array_values(array_filter(array_map('intval', $input['task_ids'])))
                    : [];
            }
            $merge = !empty($input['merge']);
            $result = $svc->generateForEvent(
                $eventId,
                $organizationId,
                !empty($input['notify']),
                $taskIds,
                $merge
            );
            if (!$result['ok']) {
                checklistJson(['success' => false, 'message' => $result['error'] ?? 'Failed to generate checklist'], 400);
            }
            checklistJson([
                'success' => true,
                'created' => $result['created'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
            ]);

        case 'template_preview':
            $eventId = (int) ($_GET['event_id'] ?? $input['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            $preview = $svc->getTemplatePreviewForEvent($eventId, $organizationId);
            if (!$preview['ok']) {
                checklistJson(['success' => false, 'message' => $preview['error'] ?? 'Failed'], 400);
            }
            checklistJson([
                'success' => true,
                'template_id' => $preview['template_id'] ?? null,
                'template_name' => $preview['template_name'] ?? '',
                'tasks' => $preview['tasks'] ?? [],
                'added_task_ids' => $preview['added_task_ids'] ?? [],
            ]);

        case 'replace_template':
            $eventId = (int) ($input['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            if (!$svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin)) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $taskIds = null;
            if (array_key_exists('task_ids', $input)) {
                $taskIds = is_array($input['task_ids'])
                    ? array_values(array_filter(array_map('intval', $input['task_ids'])))
                    : [];
            }
            $result = $svc->replaceFromTemplate($eventId, $organizationId, true, $taskIds);
            if (!$result['ok']) {
                checklistJson(['success' => false, 'message' => $result['error'] ?? 'Failed'], 400);
            }
            checklistJson([
                'success' => true,
                'created' => $result['created'] ?? $result['replaced'] ?? 0,
            ]);

        case 'recalc_due_dates':
            $eventId = (int) ($input['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            $result = $svc->recalculateDueDates($eventId, $organizationId);
            checklistJson(['success' => $result['ok'], 'message' => $result['error'] ?? null, 'updated' => $result['updated'] ?? 0]);

        case 'my_tasks':
            $filter = $_GET['filter'] ?? 'open';
            checklistJson([
                'success' => true,
                'tasks' => $svc->listItemsForAssignee($organizationId, $userId, $filter === 'all' ? null : $filter),
            ]);

        case 'mark_event_done':
            $eventId = (int) ($input['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            $can = AuthMiddleware::can('events.manage')
                || $svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin);
            if (!$can) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $db->update('events', $eventId, ['status' => 'completed']);
            checklistJson(['success' => true]);

        case 'reopen_event':
            $eventId = (int) ($input['event_id'] ?? 0);
            if ($eventId <= 0) {
                checklistJson(['success' => false, 'message' => 'event_id required'], 400);
            }
            $can = AuthMiddleware::can('events.manage')
                || $svc->canManageEventChecklist($eventId, $organizationId, $userId, $isSuperAdmin);
            if (!$can) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $db->update('events', $eventId, ['status' => 'published']);
            checklistJson(['success' => true]);

        // Template management (Settings)
        case 'templates':
            if (!$canManageTemplates) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $svc->ensureOrgDefaults($organizationId);
            checklistJson(['success' => true, 'templates' => $svc->listTemplates($organizationId)]);

        case 'template_tasks':
            if (!$canManageTemplates) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $templateId = (int) ($_GET['template_id'] ?? 0);
            if ($templateId <= 0) {
                checklistJson(['success' => false, 'message' => 'template_id required'], 400);
            }
            checklistJson(['success' => true, 'tasks' => $svc->listTemplateTasks($templateId, $organizationId)]);

        case 'save_template_task':
            if (!$canManageTemplates) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $result = $svc->saveTemplateTask($organizationId, $input);
            checklistJson(['success' => $result['ok'], 'message' => $result['error'] ?? null, 'id' => $result['id'] ?? null]);

        case 'delete_template_task':
            if (!$canManageTemplates) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $taskId = (int) ($input['task_id'] ?? 0);
            $result = $svc->deleteTemplateTask($taskId, $organizationId);
            checklistJson(['success' => $result['ok'], 'message' => $result['error'] ?? null]);

        case 'save_role':
            if (!$canManageTemplates) {
                checklistJson(['success' => false, 'message' => 'Permission denied'], 403);
            }
            $result = $svc->saveCustomRole($organizationId, $input);
            checklistJson(['success' => $result['ok'], 'message' => $result['error'] ?? null, 'id' => $result['id'] ?? null]);

        case 'expected_count':
            $eventId = (int) ($_GET['event_id'] ?? 0);
            checklistJson(['success' => true, 'count' => $svc->expectedTaskCount($organizationId, $eventId > 0 ? $eventId : 0)]);

        default:
            checklistJson(['success' => false, 'message' => 'Unknown action'], 400);
    }
} catch (\Throwable $e) {
    error_log('event-checklist API: ' . $e->getMessage());
    checklistJson(['success' => false, 'message' => 'Server error'], 500);
}
