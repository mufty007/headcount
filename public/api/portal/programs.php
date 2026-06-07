<?php
/**
 * Portal API: programs (member-only for registration; browse requires login)
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\ProgramService;
use Headcount\Services\ProgramPaymentService;

header('Content-Type: application/json');

$configFile = __DIR__ . '/../../../config/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}
$config = require $configFile;
Database::getInstance($config['database']);

if (session_status() === PHP_SESSION_NONE) {
    \Headcount\Helpers\Security::configureSession();
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?? [];

$db = Database::getInstance();
$svc = new ProgramService();
if (!$svc->tableExists('programs')) {
    echo json_encode(['success' => false, 'message' => 'Programs not available']);
    exit;
}

function portal_program_banner_url($path)
{
    if ($path === null || trim((string) $path) === '') {
        return '';
    }
    $path = trim((string) $path);
    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }
    return hc_public_api_image_url($path);
}

/**
 * @return list<array{display_name:string,title:?string,image_url:?string}>
 */
function portal_program_presenters_payload(ProgramService $svc, int $programId): array
{
    $rows = $svc->listPresenters($programId);
    $out = [];
    foreach ($rows as $r) {
        $ip = isset($r['image_path']) ? trim((string) $r['image_path']) : '';
        $imageUrl = null;
        if ($ip !== '') {
            $imageUrl = filter_var($ip, FILTER_VALIDATE_URL) ? $ip : hc_public_api_image_url($ip);
        }
        $dn = (string) ($r['display_name'] ?? '');
        $out[] = [
            'display_name' => $dn !== '' ? Utilities::decodeHtmlEntities($dn) : '',
            'title' => isset($r['title']) && $r['title'] !== null && trim((string) $r['title']) !== ''
                ? Utilities::decodeHtmlEntities(trim((string) $r['title']))
                : null,
            'image_url' => $imageUrl,
        ];
    }

    return $out;
}

PortalAuthMiddleware::requireAuth();
$memberId = PortalAuthMiddleware::getMemberId();
$orgId = PortalAuthMiddleware::getOrganizationId();

if ($method === 'GET' && isset($_GET['id']) && $_GET['id'] !== '') {
    $pid = (int) $_GET['id'];
    $p = $db->queryOne(
        "SELECT p.*, pc.name AS category_name FROM programs p
         LEFT JOIN program_categories pc ON pc.id = p.category_id
         WHERE p.id = :id AND p.organization_id = :org AND p.status = 'published'",
        ['id' => $pid, 'org' => $orgId]
    );
    if (!$p) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Not found']);
        exit;
    }
    $p['banner_image_url'] = portal_program_banner_url($p['banner_image'] ?? '');
    if (!empty($p['title'])) {
        $p['title'] = Utilities::decodeHtmlEntities($p['title']);
    }
    if (!empty($p['category_name'])) {
        $p['category_name'] = Utilities::decodeHtmlEntities($p['category_name']);
    }
    $p['questions'] = $svc->getQuestions($pid);
    $p['next_session'] = $svc->getNextSessionDate($pid);
    $p['registration'] = $svc->getRegistration($pid, $memberId);
    $p['presenters'] = portal_program_presenters_payload($svc, $pid);
    try {
        $orgWaiver = $db->queryOne(
            'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
            ['id' => $orgId]
        );
        $p['waiver'] = headcount_portal_waiver_payload(is_array($orgWaiver) ? $orgWaiver : null);
    } catch (\Throwable $e) {
        $p['waiver'] = headcount_portal_waiver_payload(null);
    }
    echo json_encode(['success' => true, 'program' => $p]);
    exit;
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'mine') {
    $mine = $svc->listMyPrograms($memberId, $orgId);
    $minePids = array_map(static function ($row) {
        return (int) ($row['program_id'] ?? 0);
    }, $mine);
    $minePresenters = $svc->listPresentersForPrograms($minePids);
    foreach ($mine as &$m) {
        if (!empty($m['title'])) {
            $m['title'] = Utilities::decodeHtmlEntities($m['title']);
        }
        $m['banner_image_url'] = portal_program_banner_url($m['banner_image'] ?? '');
        $pid = (int) ($m['program_id'] ?? 0);
        if ($pid > 0) {
            $m['next_session'] = $svc->getNextSessionDate($pid);
        } else {
            $m['next_session'] = null;
        }
        $rawPres = $minePresenters[$pid] ?? [];
        $m['presenters'] = [];
        foreach ($rawPres as $pr) {
            $ip = isset($pr['image_path']) ? trim((string) $pr['image_path']) : '';
            $imageUrl = null;
            if ($ip !== '') {
                $imageUrl = filter_var($ip, FILTER_VALIDATE_URL) ? $ip : hc_public_api_image_url($ip);
            }
            $dn = (string) ($pr['display_name'] ?? '');
            $m['presenters'][] = [
                'display_name' => $dn !== '' ? Utilities::decodeHtmlEntities($dn) : '',
                'title' => isset($pr['title']) && $pr['title'] !== null && trim((string) $pr['title']) !== ''
                    ? Utilities::decodeHtmlEntities(trim((string) $pr['title']))
                    : null,
                'image_url' => $imageUrl,
            ];
        }
    }
    unset($m);
    echo json_encode(['success' => true, 'registrations' => $mine]);
    exit;
}

if ($method === 'GET') {
    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
    $search = $_GET['search'] ?? '';
    $filters = [];
    if ($categoryId) {
        $filters['category_id'] = $categoryId;
    }
    if ($search !== '') {
        $filters['search'] = $search;
    }
    $rows = $svc->listPublishedForMemberOrg($orgId, $filters);
    $pids = array_map(static function ($row) {
        return (int) ($row['id'] ?? 0);
    }, $rows);
    $presentersByPid = $svc->listPresentersForPrograms($pids);
    foreach ($rows as &$r) {
        if (!empty($r['title'])) {
            $r['title'] = Utilities::decodeHtmlEntities($r['title']);
        }
        $r['banner_image_url'] = portal_program_banner_url($r['banner_image'] ?? '');
        $next = $svc->getNextSessionDate((int) $r['id']);
        $r['next_session'] = $next;
        $reg = $svc->getRegistration((int) $r['id'], $memberId);
        $r['my_registration_status'] = $reg ? ($reg['status'] ?? null) : null;
        $pidRow = (int) ($r['id'] ?? 0);
        $rawPres = $presentersByPid[$pidRow] ?? [];
        $r['presenters'] = [];
        foreach ($rawPres as $pr) {
            $ip = isset($pr['image_path']) ? trim((string) $pr['image_path']) : '';
            $imageUrl = null;
            if ($ip !== '') {
                $imageUrl = filter_var($ip, FILTER_VALIDATE_URL) ? $ip : hc_public_api_image_url($ip);
            }
            $dn = (string) ($pr['display_name'] ?? '');
            $r['presenters'][] = [
                'display_name' => $dn !== '' ? Utilities::decodeHtmlEntities($dn) : '',
                'title' => isset($pr['title']) && $pr['title'] !== null && trim((string) $pr['title']) !== ''
                    ? Utilities::decodeHtmlEntities(trim((string) $pr['title']))
                    : null,
                'image_url' => $imageUrl,
            ];
        }
    }
    unset($r);
    echo json_encode(['success' => true, 'programs' => $rows]);
    exit;
}

if ($method === 'POST') {
    $action = $input['action'] ?? '';
    if ($action === 'register_free') {
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];
        $orgRow = $db->queryOne(
            'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
            ['id' => $orgId]
        );
        $waiverErr = headcount_waiver_validation_error(is_array($orgRow) ? $orgRow : null, $input);
        if ($waiverErr !== null) {
            echo json_encode(['success' => false, 'message' => $waiverErr]);
            exit;
        }
        $res = $svc->registerFree($pid, $memberId, $answers);
        if (!empty($res['success']) && !empty($res['registration_id'])) {
            headcount_mark_waiver_accepted($db, 'program_registrations', (int) $res['registration_id']);
        }
        echo json_encode($res);
        exit;
    }
    if ($action === 'checkout') {
        CsrfMiddleware::verify($input);
        $pid = (int) ($input['program_id'] ?? 0);
        $coupon = $input['coupon_code'] ?? null;
        $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];
        $orgRow = $db->queryOne(
            'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
            ['id' => $orgId]
        );
        $waiverErr = headcount_waiver_validation_error(is_array($orgRow) ? $orgRow : null, $input);
        if ($waiverErr !== null) {
            echo json_encode(['success' => false, 'message' => $waiverErr]);
            exit;
        }
        $pending = $svc->createPendingRegistration($pid, $memberId, $answers, $coupon);
        if (!$pending['success']) {
            echo json_encode($pending);
            exit;
        }
        $regId = (int) $pending['registration_id'];
        headcount_mark_waiver_accepted($db, 'program_registrations', $regId);
        $pay = new ProgramPaymentService();
        $res = $pay->createCheckoutSession($pid, $memberId, $regId, $coupon);
        echo json_encode($res);
        exit;
    }
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Not found']);
