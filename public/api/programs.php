<?php
/**
 * Admin API: Programs (CRUD, categories, questions, sessions, attendance, coupons, announcements)
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
use Headcount\Services\ProgramService;
use Headcount\Services\EmailService;

header('Content-Type: application/json');

$configFile = HC_PROJECT_ROOT . '/config/config.php';
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
$userRole = $_SESSION['role'] ?? 'admin';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if ($method === 'POST' && stripos($contentType, 'multipart/form-data') !== false) {
    $rawPayload = $_POST['payload'] ?? '';
    $input = is_string($rawPayload) ? (json_decode($rawPayload, true) ?: []) : [];
} else {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}
$action = $_GET['action'] ?? ($input['action'] ?? 'list');

$svc = new ProgramService();
if (!$svc->tableExists('programs')) {
    jsonResponse(['success' => false, 'message' => 'Programs tables not installed. Run database/migrations/039_programs_domain.sql'], 503);
}

try {
    if ($method === 'GET' && $action === 'list') {
        AuthMiddleware::requireAdminOrCoordinator();
        $filters = ['status' => $_GET['status'] ?? null, 'search' => $_GET['search'] ?? ''];
        if (empty($filters['status'])) {
            unset($filters['status']);
        }
        if (empty($filters['search'])) {
            unset($filters['search']);
        }
        $rows = $svc->listForOrg($organizationId, $filters);
        jsonResponse(['success' => true, 'programs' => $rows]);
    }

    if ($method === 'GET' && $action === 'get') {
        AuthMiddleware::requireAdminOrCoordinator();
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid id'], 400);
        }
        $p = $svc->getByIdForOrg($id, $organizationId);
        if (!$p) {
            jsonResponse(['success' => false, 'message' => 'Not found'], 404);
        }
        $p['questions'] = $svc->getQuestions($id);
        if (!empty($p['session_days_of_week'])) {
            $dec = json_decode($p['session_days_of_week'], true);
            $p['session_days_of_week'] = is_array($dec) ? $dec : [];
        } else {
            $p['session_days_of_week'] = [];
        }
        $p['staff'] = $svc->listStaff($id);
        $p['presenters'] = $svc->listPresenters($id);
        $p['weeks'] = $svc->listWeeksWithSessions($id);
        jsonResponse(['success' => true, 'program' => $p]);
    }

    if ($method === 'POST' && $action === 'save') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);

        $editId = isset($input['id']) ? (int) $input['id'] : null;
        $existingBanner = null;
        $exProg = null;
        if ($editId) {
            $exProg = $svc->getByIdForOrg($editId, $organizationId);
            $existingBanner = $exProg['banner_image'] ?? null;
        }

        $uploadConfig = $config['uploads'] ?? [];
        $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $uploadConfig['max_size'] = 5242880;
        if (empty($uploadConfig['upload_path'])) {
            $uploadConfig['upload_path'] = __DIR__ . '/../../uploads/';
        }
        $uploadConfig['upload_path'] = rtrim(realpath($uploadConfig['upload_path']) ?: $uploadConfig['upload_path'], '/\\') . '/';

        if (!empty($input['remove_banner_image'])) {
            $input['banner_image'] = null;
            if (!empty($existingBanner)) {
                $oldPath = $uploadConfig['upload_path'] . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($existingBanner, '/\\'));
                if (file_exists($oldPath) && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
        } elseif (!empty($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $fileUpload = new FileUpload($uploadConfig);
                $uploadResult = $fileUpload->upload($_FILES['banner_image'], 'program-banners');
                $newPath = 'program-banners/' . $uploadResult['filename'];
                $full = $uploadConfig['upload_path'] . str_replace('/', DIRECTORY_SEPARATOR, $newPath);
                if (file_exists($full) && is_file($full)) {
                    if (!empty($existingBanner) && $existingBanner !== $newPath) {
                        $oldFull = $uploadConfig['upload_path'] . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($existingBanner, '/\\'));
                        if (file_exists($oldFull) && is_file($oldFull)) {
                            @unlink($oldFull);
                        }
                    }
                    $input['banner_image'] = $newPath;
                }
            } catch (\Throwable $e) {
                error_log('program banner upload: ' . $e->getMessage());
                jsonResponse(['success' => false, 'message' => 'Banner upload failed: ' . $e->getMessage()], 400);
            }
        }

        unset($input['remove_banner_image']);

        $res = $svc->saveProgram($organizationId, $userId, $input, $editId ?: null);
        if (!$res['success']) {
            jsonResponse($res, 400);
        }
        $pid = (int) $res['id'];
        try {
            $svc->replacePresentersFromAdminInput($pid, $input, $_FILES ?? [], $config);
        } catch (\Throwable $e) {
            error_log('program presenters save: ' . $e->getMessage());
        }
        if (array_key_exists('questions', $input) && is_array($input['questions'])) {
            $svc->saveQuestions($pid, $organizationId, $input['questions']);
        }
        if (isset($input['weeks']) && is_array($input['weeks'])) {
            $weekRes = $svc->saveWeeksFromAdmin($pid, $organizationId, $input['weeks']);
            if (empty($weekRes['success'])) {
                jsonResponse($weekRes, 400);
            }
        }
        $saved = $svc->getByIdForOrg($pid, $organizationId);
        if ($exProg && $saved) {
            try {
                $notifier = new \Headcount\Services\ScheduleChangeNotificationService($config);
                $notifier->notifyProgramIfScheduleChanged($pid, $organizationId, $exProg, $saved);
            } catch (\Throwable $e) {
                error_log('Program schedule change notification: ' . $e->getMessage());
            }
        }
        jsonResponse([
            'success' => true,
            'id' => $pid,
            'banner_image' => $saved['banner_image'] ?? null,
        ]);
    }

    if ($method === 'POST' && $action === 'delete') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $id = (int) ($input['id'] ?? 0);
        $res = $svc->deleteProgram($id, $organizationId);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'GET' && $action === 'categories') {
        AuthMiddleware::requireAdminOrCoordinator();
        jsonResponse(['success' => true, 'categories' => $svc->listCategories($organizationId)]);
    }

    if ($method === 'POST' && $action === 'save_category') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $res = $svc->saveCategory($organizationId, $input, isset($input['id']) ? (int) $input['id'] : null);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'delete_category') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $cid = (int) ($input['id'] ?? 0);
        $res = $svc->deleteCategory($organizationId, $cid);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'generate_sessions') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        $res = $svc->generateSessions($pid, $organizationId, (int) ($input['horizon_months'] ?? 6));
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'GET' && $action === 'sessions') {
        AuthMiddleware::requireAdminOrCoordinator();
        $pid = (int) ($_GET['program_id'] ?? 0);
        $rows = $svc->listSessions($pid, $organizationId, $_GET['from'] ?? null, $_GET['to'] ?? null);
        jsonResponse(['success' => true, 'sessions' => $rows]);
    }

    if ($method === 'GET' && $action === 'attendance_roster') {
        AuthMiddleware::requireAdminOrCoordinator();
        $sid = (int) ($_GET['session_id'] ?? 0);
        $roster = $svc->getSessionAttendanceRoster($sid, $organizationId);
        if ($roster === null) {
            jsonResponse(['success' => false, 'message' => 'Session not found'], 404);
        }
        jsonResponse(['success' => true] + $roster);
    }

    if ($method === 'GET' && $action === 'registrants') {
        AuthMiddleware::requireAdminOrCoordinator();
        $pid = (int) ($_GET['program_id'] ?? 0);
        jsonResponse(['success' => true, 'registrants' => $svc->listActiveRegistrantsWithWeeks($pid, $organizationId)]);
    }

    if ($method === 'GET' && $action === 'pending_registrants') {
        AuthMiddleware::requireAdminOrCoordinator();
        $pid = (int) ($_GET['program_id'] ?? 0);
        jsonResponse(['success' => true, 'pending' => $svc->listPendingRegistrationsForAdmin($pid, $organizationId)]);
    }

    if ($method === 'POST' && $action === 'add_sponsored_enrollment') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        if ($pid <= 0) {
            jsonResponse(['success' => false, 'message' => 'Program is required'], 400);
        }
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $memberId = (int) ($input['user_id'] ?? 0);
        $firstName = isset($input['first_name']) ? trim((string) $input['first_name']) : '';
        $lastName = isset($input['last_name']) ? trim((string) $input['last_name']) : '';
        $email = isset($input['email']) ? trim(strtolower((string) $input['email'])) : '';

        if ($memberId <= 0 && $email === '') {
            jsonResponse(['success' => false, 'message' => 'Select a member or enter name and email.'], 400);
        }

        $weekIds = [];
        if (isset($input['week_ids']) && is_array($input['week_ids'])) {
            $weekIds = array_map('intval', $input['week_ids']);
        }
        $note = isset($input['note']) ? trim((string) $input['note']) : null;

        $res = $svc->adminEnrollSponsoredMember(
            $pid,
            $organizationId,
            $userId,
            $weekIds,
            $note,
            $memberId > 0 ? $memberId : null,
            $email !== '' ? $email : null,
            $firstName !== '' ? $firstName : null,
            $lastName !== '' ? $lastName : null
        );
        if (empty($res['success'])) {
            jsonResponse($res, 400);
        }

        $program = $svc->getByIdForOrg($pid, $organizationId);
        $participant = $res['user'] ?? null;
        $needsProfile = !empty($res['needs_profile']);
        $isNewUser = !empty($res['is_new_user']);
        $emailSent = false;
        $emailError = null;

        $smtp = $config['smtp2go'] ?? [];
        if (!empty($smtp['api_key']) && $program && $participant) {
            try {
                $emailSvc = new EmailService($smtp);
                $portalBase = headcount_portal_base_url($config);
                $programUrl = headcount_program_portal_url($config, $pid);
                $registerUrl = $portalBase . '/portal/register.php?email=' . urlencode((string) ($participant['email'] ?? $email));
                $sendResult = $emailSvc->sendSponsoredProgramEnrollmentEmail(
                    $program,
                    $participant,
                    $organizationId,
                    $programUrl,
                    $registerUrl,
                    $needsProfile
                );
                $emailSent = !empty($sendResult['success']);
                if (!$emailSent) {
                    $emailError = $sendResult['error'] ?? 'Email could not be sent';
                }
            } catch (\Throwable $e) {
                $emailError = $e->getMessage();
                error_log('add_sponsored_enrollment email: ' . $e->getMessage());
            }
        }

        jsonResponse(array_merge($res, [
            'is_new_user' => $isNewUser,
            'needs_profile' => $needsProfile,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ]), 200);
    }

    if ($method === 'POST' && $action === 'attendance') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $res = $svc->recordAttendance(
            (int) ($input['session_id'] ?? 0),
            (int) ($input['user_id'] ?? 0),
            $input['status'] ?? 'present',
            $userId,
            $organizationId
        );
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'staff') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        $ids = isset($input['user_ids']) && is_array($input['user_ids']) ? $input['user_ids'] : [];
        $res = $svc->setStaff($pid, $organizationId, $ids, $input['role'] ?? 'coordinator');
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'GET' && $action === 'coupons') {
        AuthMiddleware::requireAdminOrCoordinator();
        jsonResponse(['success' => true, 'coupons' => $svc->listCoupons($organizationId)]);
    }

    if ($method === 'POST' && $action === 'save_coupon') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $res = $svc->saveCoupon($organizationId, $input, isset($input['id']) ? (int) $input['id'] : null);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'announce') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $smtp = $config['smtp2go'] ?? [];
        if (empty($smtp['api_key'])) {
            jsonResponse(['success' => false, 'message' => 'Email not configured'], 400);
        }
        $email = new EmailService($smtp);
        $subject = trim($input['subject'] ?? '');
        $body = $input['body'] ?? '';
        if ($subject === '' || $body === '') {
            jsonResponse(['success' => false, 'message' => 'Subject and body required'], 400);
        }
        $org = Database::getInstance()->queryOne("SELECT name, logo_path FROM organizations WHERE id = :id", ['id' => $organizationId]);
        $branding = ['org_name' => $org['name'] ?? ''];
        $result = $email->sendProgramAnnouncement($pid, $organizationId, $subject, $body, $branding);
        jsonResponse(['success' => true, 'result' => $result]);
    }


    // ─── Program Categories ───────────────────────────────────────────────────

    if ($action === 'categories') {
        AuthMiddleware::requireAdmin();
        $db = Database::getInstance();
        // Auto-create table if needed
        $db->execute("CREATE TABLE IF NOT EXISTS program_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organization_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(130) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_org (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = $db->query(
            "SELECT id, name, slug, sort_order FROM program_categories WHERE organization_id = ? ORDER BY sort_order, name",
            [$organizationId]
        );
        jsonResponse(['success' => true, 'categories' => $rows ?: []]);
    }

    if ($method === 'POST' && $action === 'save_category') {
        AuthMiddleware::requireAdmin();
        CsrfMiddleware::verify($input);
        $db = Database::getInstance();
        $db->execute("CREATE TABLE IF NOT EXISTS program_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organization_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(130) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_org (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            jsonResponse(['success' => false, 'message' => 'Name is required'], 400);
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim($slug, '-');
        $sortOrder = (int) ($input['sort_order'] ?? 0);
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if ($id > 0) {
            // verify ownership
            $existing = $db->queryOne("SELECT id FROM program_categories WHERE id = ? AND organization_id = ?", [$id, $organizationId]);
            if (!$existing) {
                jsonResponse(['success' => false, 'message' => 'Category not found'], 404);
            }
            $db->execute("UPDATE program_categories SET name = ?, slug = ?, sort_order = ? WHERE id = ? AND organization_id = ?",
                [$name, $slug, $sortOrder, $id, $organizationId]);
            jsonResponse(['success' => true, 'id' => $id]);
        } else {
            $newId = $db->insert('program_categories', [
                'organization_id' => $organizationId,
                'name'            => $name,
                'slug'            => $slug,
                'sort_order'      => $sortOrder,
            ]);
            jsonResponse(['success' => true, 'id' => $newId]);
        }
    }

    if ($method === 'POST' && $action === 'delete_category') {
        AuthMiddleware::requireAdmin();
        CsrfMiddleware::verify($input);
        $db = Database::getInstance();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid id'], 400);
        }
        $existing = $db->queryOne("SELECT id FROM program_categories WHERE id = ? AND organization_id = ?", [$id, $organizationId]);
        if (!$existing) {
            jsonResponse(['success' => false, 'message' => 'Category not found'], 404);
        }
        $db->execute("DELETE FROM program_categories WHERE id = ? AND organization_id = ?", [$id, $organizationId]);
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action'], 404);

} catch (\Throwable $e) {
    error_log('programs API: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
