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
use Headcount\Services\ProgramRequestService;
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
        AuthMiddleware::requireAdminCoordinatorOrPresenter();
        $filters = ['status' => $_GET['status'] ?? null, 'search' => $_GET['search'] ?? ''];
        if (empty($filters['status'])) {
            unset($filters['status']);
        }
        if (empty($filters['search'])) {
            unset($filters['search']);
        }
        if ($userRole === 'presenter') {
            $filters['presenter_user_id'] = $userId;
        }
        $rows = $svc->listForOrg($organizationId, $filters);
        jsonResponse(['success' => true, 'programs' => $rows]);
    }

    if ($method === 'GET' && $action === 'get') {
        AuthMiddleware::requireAdminCoordinatorOrPresenter();
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid id'], 400);
        }
        if ($userRole === 'presenter' && !$svc->userIsAssignedPresenter($userId, $id, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
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
        try {
            $p['facility_ids'] = (new \Headcount\Services\EventFacilityService())->idsForProgram($id);
        } catch (\Throwable $e) {
            $p['facility_ids'] = [];
        }
        jsonResponse(['success' => true, 'program' => $p]);
    }

    if ($method === 'POST' && $action === 'save') {
        CsrfMiddleware::verify($input);
        $editId = isset($input['id']) ? (int) $input['id'] : null;
        if (!$editId) {
            AuthMiddleware::requireCan('programs.manage');
        } elseif (!AuthMiddleware::canMaintainExistingProgram($organizationId, $editId)) {
            jsonResponse(['success' => false, 'message' => 'You do not have permission to save this program.'], 403);
        }

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

        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if ($status === 'published' && !empty($input['facility_ids']) && is_array($input['facility_ids'])) {
            $facIds = array_values(array_filter(array_map('intval', $input['facility_ids'])));
            $startsOn = trim((string) ($input['starts_on'] ?? ''));
            $st = trim((string) ($input['session_start_time'] ?? ''));
            $et = trim((string) ($input['session_end_time'] ?? ''));
            if ($facIds && $startsOn !== '' && $st !== '' && $et !== '') {
                $conflicts = (new \Headcount\Services\EventFacilityService())->conflictMessages(
                    $organizationId,
                    $facIds,
                    $startsOn,
                    $st,
                    $et,
                    0,
                    $editId ? (int) $editId : 0
                );
                if ($conflicts) {
                    jsonResponse(['success' => false, 'message' => implode(' ', $conflicts), 'errors' => $conflicts], 400);
                }
            }
        }

        $res = $svc->saveProgram($organizationId, $userId, $input, $editId ?: null);
        if (!$res['success']) {
            jsonResponse($res, 400);
        }
        $pid = (int) $res['id'];
        if (array_key_exists('facility_ids', $input)) {
            try {
                (new \Headcount\Services\EventFacilityService())->syncProgram($pid, $organizationId, is_array($input['facility_ids']) ? $input['facility_ids'] : []);
            } catch (\Throwable $e) {
                error_log('program facilities save: ' . $e->getMessage());
            }
        }
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
        $id = (int) ($input['id'] ?? 0);
        if (!AuthMiddleware::canMaintainExistingProgram($organizationId, $id)) {
            jsonResponse(['success' => false, 'message' => 'You do not have permission to delete this program.'], 403);
        }
        CsrfMiddleware::verify($input);
        $res = $svc->deleteProgram($id, $organizationId);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'GET' && $action === 'categories') {
        AuthMiddleware::requireAdminOrCoordinator();
        jsonResponse(['success' => true, 'categories' => $svc->listCategories($organizationId)]);
    }

    if ($method === 'POST' && $action === 'save_category') {
        AuthMiddleware::requireCan('programs.manage');
        CsrfMiddleware::verify($input);
        $res = $svc->saveCategory($organizationId, $input, isset($input['id']) ? (int) $input['id'] : null);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'delete_category') {
        AuthMiddleware::requireCan('programs.manage');
        CsrfMiddleware::verify($input);
        $cid = (int) ($input['id'] ?? 0);
        $res = $svc->deleteCategory($organizationId, $cid);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'generate_sessions') {
        @set_time_limit(90);
        ignore_user_abort(true);
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        if (!AuthMiddleware::canMaintainExistingProgram($organizationId, $pid)
            && !$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'You do not have permission to generate sessions.'], 403);
        }
        $res = $svc->generateSessions(
            $pid,
            $organizationId,
            (int) ($input['horizon_months'] ?? 6),
            !empty($input['update_existing'])
        );
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'save_session') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        if ($pid <= 0) {
            jsonResponse(['success' => false, 'message' => 'Program is required'], 400);
        }
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $sessionId = isset($input['id']) ? (int) $input['id'] : 0;
        $res = $svc->adminSaveSession(
            $pid,
            $organizationId,
            $input,
            $sessionId > 0 ? $sessionId : null
        );
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'set_session_status') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $sid = (int) ($input['session_id'] ?? $input['id'] ?? 0);
        $sess = $svc->getSessionForOrg($sid, $organizationId);
        if (!$sess) {
            jsonResponse(['success' => false, 'message' => 'Session not found'], 404);
        }
        $pid = (int) ($sess['program_id'] ?? 0);
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $res = $svc->adminSetSessionStatus($sid, $organizationId, (string) ($input['status'] ?? ''));
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'delete_session') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $sid = (int) ($input['session_id'] ?? $input['id'] ?? 0);
        $sess = $svc->getSessionForOrg($sid, $organizationId);
        if (!$sess) {
            jsonResponse(['success' => false, 'message' => 'Session not found'], 404);
        }
        $pid = (int) ($sess['program_id'] ?? 0);
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $res = $svc->adminDeleteSession($sid, $organizationId);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'GET' && $action === 'sessions') {
        AuthMiddleware::requireAdminCoordinatorOrPresenter();
        $pid = (int) ($_GET['program_id'] ?? 0);
        if ($userRole === 'presenter' && !$svc->userIsAssignedPresenter($userId, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $pid = (int) ($_GET['program_id'] ?? 0);
        $rows = $svc->listSessions($pid, $organizationId, $_GET['from'] ?? null, $_GET['to'] ?? null);
        jsonResponse(['success' => true, 'sessions' => $rows]);
    }

    if ($method === 'GET' && $action === 'attendance_roster') {
        AuthMiddleware::requireAdminCoordinatorOrPresenter();
        $sid = (int) ($_GET['session_id'] ?? 0);
        $roster = $svc->getSessionAttendanceRoster($sid, $organizationId);
        if ($roster === null) {
            jsonResponse(['success' => false, 'message' => 'Session not found'], 404);
        }
        $pid = (int) ($roster['session']['program_id'] ?? 0);
        if ($userRole === 'presenter' && !$svc->userIsAssignedPresenter($userId, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
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

        $smtp = headcount_resolve_smtp_config($organizationId, $config);
        if ($smtp && $program && $participant) {
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
        } elseif ($program && $participant) {
            $emailError = 'Email is not configured. Add your SMTP2GO API key in Settings → Email.';
        }

        jsonResponse(array_merge($res, [
            'is_new_user' => $isNewUser,
            'needs_profile' => $needsProfile,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ]), 200);
    }

    if ($method === 'POST' && $action === 'remove_registrant') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        $memberUserId = (int) ($input['user_id'] ?? 0);
        if ($pid <= 0 || $memberUserId <= 0) {
            jsonResponse(['success' => false, 'message' => 'Program and registrant are required'], 400);
        }
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $res = $svc->adminCancelRegistration($pid, $organizationId, $memberUserId);
        jsonResponse($res, $res['success'] ? 200 : 400);
    }

    if ($method === 'POST' && $action === 'replace_registrant') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        $fromUserId = (int) ($input['from_user_id'] ?? 0);
        if ($pid <= 0 || $fromUserId <= 0) {
            jsonResponse(['success' => false, 'message' => 'Select the person to replace'], 400);
        }
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $toUserId = (int) ($input['user_id'] ?? 0);
        $firstName = isset($input['first_name']) ? trim((string) $input['first_name']) : '';
        $lastName = isset($input['last_name']) ? trim((string) $input['last_name']) : '';
        $email = isset($input['email']) ? trim(strtolower((string) $input['email'])) : '';
        if ($toUserId <= 0 && $email === '') {
            jsonResponse(['success' => false, 'message' => 'Select a member or enter name and email for the replacement.'], 400);
        }

        $res = $svc->adminReplaceRegistrant(
            $pid,
            $organizationId,
            $userId,
            $fromUserId,
            $toUserId > 0 ? $toUserId : null,
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
        $emailSent = false;
        $emailError = null;
        $smtp = headcount_resolve_smtp_config($organizationId, $config);
        if ($smtp && $program && $participant) {
            try {
                $emailSvc = new EmailService($smtp);
                $portalBase = headcount_portal_base_url($config);
                $programUrl = headcount_program_portal_url($config, $pid);
                $registerUrl = $portalBase . '/portal/register.php?email=' . urlencode((string) ($participant['email'] ?? $email));
                $sendResult = $emailSvc->sendProgramSeatTransferEmail(
                    $program,
                    $participant,
                    $organizationId,
                    $programUrl,
                    $registerUrl,
                    $needsProfile,
                    (string) ($res['from_name'] ?? '')
                );
                $emailSent = !empty($sendResult['success']);
                if (!$emailSent) {
                    $emailError = $sendResult['error'] ?? 'Email could not be sent';
                }
            } catch (\Throwable $e) {
                $emailError = $e->getMessage();
                error_log('replace_registrant email: ' . $e->getMessage());
            }
        }

        jsonResponse(array_merge($res, [
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ]), 200);
    }

    if ($method === 'POST' && $action === 'attendance') {
        AuthMiddleware::requireAdminCoordinatorOrPresenter();
        CsrfMiddleware::verify($input);
        $sessionId = (int) ($input['session_id'] ?? 0);
        if ($userRole === 'presenter') {
            $roster = $svc->getSessionAttendanceRoster($sessionId, $organizationId);
            $pid = (int) ($roster['session']['program_id'] ?? 0);
            if (!$svc->userIsAssignedPresenter($userId, $pid, $organizationId)) {
                jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }
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

    if ($method === 'GET' && $action === 'email_logs') {
        AuthMiddleware::requireAdminOrCoordinator();
        $pid = (int) ($_GET['program_id'] ?? 0);
        if ($pid <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid program'], 400);
        }
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $db = Database::getInstance();
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
        if (!$db->hasColumn('email_logs', 'program_id')) {
            jsonResponse(['success' => true, 'logs' => [], 'total' => 0]);
        }
        $params = ['org' => $organizationId, 'pid' => $pid];
        $logs = $db->query(
            "SELECT el.id, el.program_id, el.recipient_user_id, el.recipient_email, el.subject,
                    el.email_type, el.status, el.error_message, el.sent_at, el.created_at,
                    u.first_name AS recipient_first_name, u.last_name AS recipient_last_name
             FROM email_logs el
             LEFT JOIN users u ON el.recipient_user_id = u.id
             WHERE el.organization_id = :org AND el.program_id = :pid
             ORDER BY el.created_at DESC
             LIMIT " . (int) $limit,
            $params
        ) ?: [];
        foreach ($logs as &$logRow) {
            if (!empty($logRow['subject']) && function_exists('headcount_flatten_ampersand_in_plain_text')) {
                $logRow['subject'] = headcount_flatten_ampersand_in_plain_text((string) $logRow['subject']);
            }
        }
        unset($logRow);
        $totalRow = $db->queryOne(
            'SELECT COUNT(*) AS c FROM email_logs WHERE organization_id = :org AND program_id = :pid',
            $params
        );
        jsonResponse(['success' => true, 'logs' => $logs, 'total' => (int) ($totalRow['c'] ?? 0)]);
    }

    if ($method === 'POST' && $action === 'announce') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        if (!$svc->userCanManageProgram($userId, $userRole, $pid, $organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $smtp = headcount_resolve_smtp_config($organizationId, $config);
        if ($smtp === null) {
            jsonResponse(['success' => false, 'message' => 'Email is not configured. Add your SMTP2GO API key in Settings → Email.'], 400);
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
