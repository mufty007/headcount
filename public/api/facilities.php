<?php
/**
 * Admin API: Facilities CRUD
 */
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Core\FileUpload;
use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityService;
use Headcount\Services\ActivityLogger;

header('Content-Type: application/json');

$configFile = __DIR__ . '/../../config/config.php';
if (!file_exists($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Config missing'], 500);
}
$config = require $configFile;
Database::getInstance($config['database']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

AuthMiddleware::check();
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if ($method === 'POST' && stripos($contentType, 'multipart/form-data') !== false) {
    $rawPayload = $_POST['payload'] ?? '';
    $input = is_string($rawPayload) ? (json_decode($rawPayload, true) ?: []) : [];
} else {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}
$action = $_GET['action'] ?? ($input['action'] ?? 'list');

$svc = new FacilityService();
if (!$svc->tableExists()) {
    jsonResponse(['success' => false, 'message' => 'Facilities tables not installed. Run database/migrations/059_facilities_domain.sql'], 503);
}

try {
    if ($method === 'GET' && $action === 'list') {
        AuthMiddleware::requireAdminOrCoordinator();
        $filters = [];
        if (!empty($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }
        jsonResponse(['success' => true, 'facilities' => $svc->listForOrg($organizationId, $filters)]);
    }

    if ($method === 'GET' && $action === 'get') {
        AuthMiddleware::requireAdminOrCoordinator();
        $id = (int) ($_GET['id'] ?? 0);
        $row = $svc->getByIdForOrg($id, $organizationId);
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Not found'], 404);
        }
        $managers = $svc->getManagers($id, $organizationId);
        $row['managers'] = $managers;
        $row['manager_ids'] = array_map(static fn ($m) => (int) $m['id'], $managers);
        jsonResponse(['success' => true, 'facility' => $row]);
    }

    if ($method === 'GET' && $action === 'eligible-managers') {
        AuthMiddleware::requireAdmin();
        jsonResponse(['success' => true, 'users' => $svc->listEligibleManagers($organizationId)]);
    }

    if ($method === 'POST' && $action === 'save') {
        AuthMiddleware::requireAdmin();
        CsrfMiddleware::verify($input);
        $editId = !empty($input['id']) ? (int) $input['id'] : null;

        $existingImages = [];
        if ($editId) {
            $existing = $svc->getByIdForOrg($editId, $organizationId);
            if ($existing) {
                $existingImages = is_array($existing['images'] ?? null) ? $existing['images'] : [];
            }
        }

        $uploadConfig = $config['uploads'] ?? [];
        $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $uploadConfig['max_size'] = 5242880;
        if (empty($uploadConfig['upload_path'])) {
            $uploadConfig['upload_path'] = __DIR__ . '/../../uploads/';
        }
        $uploadConfig['upload_path'] = rtrim(realpath($uploadConfig['upload_path']) ?: $uploadConfig['upload_path'], '/\\') . '/';

        $keptImages = [];
        if (!empty($input['images']) && is_array($input['images'])) {
            foreach ($input['images'] as $path) {
                $path = trim((string) $path);
                if ($path !== '') {
                    $keptImages[] = $path;
                }
            }
        }

        $removed = array_diff($existingImages, $keptImages);
        foreach ($removed as $oldPath) {
            $oldFull = $uploadConfig['upload_path'] . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($oldPath, '/\\'));
            if (file_exists($oldFull) && is_file($oldFull)) {
                @unlink($oldFull);
            }
        }

        if (!empty($_FILES['facility_images'])) {
            $files = $_FILES['facility_images'];
            $fileUpload = new FileUpload($uploadConfig);
            $count = is_array($files['name']) ? count($files['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $single = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];
                try {
                    $uploadResult = $fileUpload->upload($single, 'facility-images');
                    $keptImages[] = 'facility-images/' . $uploadResult['filename'];
                } catch (\Throwable $e) {
                    error_log('facility image upload: ' . $e->getMessage());
                    jsonResponse(['success' => false, 'message' => 'Image upload failed: ' . $e->getMessage()], 400);
                }
            }
        }

        $input['images'] = $keptImages;

        if (!empty($input['is_paid']) && (float) ($input['hourly_rate'] ?? 0) <= 0) {
            jsonResponse(['success' => false, 'message' => 'Hourly rate is required for paid facilities.'], 400);
        }

        $res = $svc->saveFacility($organizationId, $input, $editId);
        if (!$res['success']) {
            jsonResponse($res, 400);
        }
        $savedId = (int) $res['id'];
        if ($svc->managersTableExists() && array_key_exists('manager_ids', $input)) {
            $managerIds = is_array($input['manager_ids']) ? $input['manager_ids'] : [];
            $svc->setManagers($savedId, $organizationId, $managerIds);
        }
        $facility = $svc->getByIdForOrg($savedId, $organizationId);
        if ($facility) {
            $managers = $svc->getManagers($savedId, $organizationId);
            $facility['managers'] = $managers;
            $facility['manager_ids'] = array_map(static fn ($m) => (int) $m['id'], $managers);
        }
        $logger = new ActivityLogger($organizationId, $userId);
        $logger->log(
            $editId ? 'facility_updated' : 'facility_created',
            ($editId ? 'Updated' : 'Created') . ' facility: ' . ($input['name'] ?? ''),
            'facility',
            $savedId
        );
        jsonResponse(['success' => true, 'id' => $savedId, 'facility' => $facility]);
    }

    if ($method === 'POST' && $action === 'delete') {
        AuthMiddleware::requireAdmin();
        CsrfMiddleware::verify($input);
        $id = (int) ($input['id'] ?? 0);
        $res = $svc->deleteFacility($id, $organizationId);
        if (!$res['success']) {
            jsonResponse($res, 400);
        }
        $logger = new ActivityLogger($organizationId, $userId);
        $logger->log('facility_deleted', 'Deleted facility #' . $id, 'facility', $id);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
} catch (\Throwable $e) {
    error_log('facilities API: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Server error'], 500);
}
