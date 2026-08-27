<?php
/**
 * Admin API: event requests (list, create, update, resubmit, withdraw, send back, approve, decline)
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
use Headcount\Services\ProgramRequestService;

header('Content-Type: application/json');

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Config missing'], 500);
}
$config = require $configFile;
Database::getInstance($config['database']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = (int) AuthMiddleware::getOrganizationId();
$userId = (int) AuthMiddleware::getUserId();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);
    $input = is_array($decoded) ? $decoded : $_POST;
    CsrfMiddleware::verify($input);
}

$action = $_GET['action'] ?? ($input['action'] ?? 'list');
$service = new ProgramRequestService();
if (!$service->tablesExist()) {
    jsonResponse(['success' => false, 'message' => 'Program requests are not installed. Run migration 087_program_requests.sql.'], 503);
}

$canRequest = AuthMiddleware::can('programs.request');
$canApprove = AuthMiddleware::can('programs.approve_requests');

$proposalFromInput = static function (array $src): array {
    return [
        'title' => trim((string) ($src['title'] ?? '')),
        'description' => trim((string) ($src['description'] ?? '')),
        'starts_on' => trim((string) ($src['starts_on'] ?? $src['event_date'] ?? '')),
        'session_start_time' => trim((string) ($src['session_start_time'] ?? $src['start_time'] ?? '')),
        'session_end_time' => trim((string) ($src['session_end_time'] ?? $src['end_time'] ?? '')),
        'location' => trim((string) ($src['location'] ?? '')),
        'facility_ids' => $src['facility_ids'] ?? [],
        'category' => trim((string) ($src['category'] ?? '')),
        'budget' => $src['budget'] ?? '',
        'target_attendance' => $src['target_attendance'] ?? '',
        'target_audience' => trim((string) ($src['target_audience'] ?? '')),
        'notes' => trim((string) ($src['notes'] ?? '')),
    ];
};

try {
    if ($action === 'list' && $method === 'GET') {
        if (!$canRequest && !$canApprove) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $filters = [];
        $status = trim((string) ($_GET['status'] ?? ''));
        if ($status !== '') {
            $filters['status'] = $status;
        }
        if (!$canApprove) {
            $filters['submitted_by'] = $userId;
        } elseif (isset($_GET['mine']) && $_GET['mine'] === '1') {
            $filters['submitted_by'] = $userId;
        }
        $rows = $service->listForOrg($organizationId, $filters);
        jsonResponse(['success' => true, 'requests' => $rows, 'pending_count' => $service->countPending($organizationId)]);
    }

    if ($action === 'get' && $method === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        $row = $service->getById($id, $organizationId);
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Request not found.'], 404);
        }
        if (!$canApprove && (int) $row['submitted_by'] !== $userId) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $row['comments'] = $service->commentsFor($id);
        jsonResponse(['success' => true, 'request' => $row]);
    }

    if ($action === 'create' && $method === 'POST') {
        if (!$canRequest) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $id = $service->create($organizationId, $userId, $proposalFromInput($input));
        jsonResponse(['success' => true, 'id' => $id, 'message' => 'Program request submitted.']);
    }

    if ($action === 'update' && $method === 'POST') {
        if (!$canRequest) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $id = (int) ($input['id'] ?? 0);
        $service->updateProposal($id, $organizationId, $userId, $proposalFromInput($input));
        jsonResponse(['success' => true, 'message' => 'Request updated.']);
    }

    if ($action === 'resubmit' && $method === 'POST') {
        if (!$canRequest) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $id = (int) ($input['id'] ?? 0);
        $service->resubmit($id, $organizationId, $userId, trim((string) ($input['message'] ?? '')));
        jsonResponse(['success' => true, 'message' => 'Request resubmitted.']);
    }

    if ($action === 'withdraw' && $method === 'POST') {
        if (!$canRequest) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $id = (int) ($input['id'] ?? 0);
        $service->withdraw($id, $organizationId, $userId);
        jsonResponse(['success' => true, 'message' => 'Request withdrawn.']);
    }

    if ($action === 'send_back' && $method === 'POST') {
        if (!$canApprove) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $id = (int) ($input['id'] ?? 0);
        $service->sendBack($id, $organizationId, $userId, (string) ($input['comment'] ?? ''));
        jsonResponse(['success' => true, 'message' => 'Request sent back for updates.']);
    }

    if ($action === 'decline' && $method === 'POST') {
        if (!$canApprove) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $id = (int) ($input['id'] ?? 0);
        $service->decline($id, $organizationId, $userId, (string) ($input['comment'] ?? ''));
        jsonResponse(['success' => true, 'message' => 'Request declined.']);
    }

    if ($action === 'approve' && $method === 'POST') {
        if (!$canApprove) {
            jsonResponse(['success' => false, 'message' => 'Permission denied.'], 403);
        }
        $id = (int) ($input['id'] ?? 0);
        $programId = $service->approve($id, $organizationId, $userId, trim((string) ($input['comment'] ?? '')));
        jsonResponse(['success' => true, 'program_id' => $programId, 'message' => 'Request approved. A draft program was created.']);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (InvalidArgumentException $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('program-requests API: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Unable to process program request.'], 500);
}
