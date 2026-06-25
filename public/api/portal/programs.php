<?php
/**
 * Portal API: programs — public browse; sign-in required to register or view My Programs
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
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\ProgramService;
use Headcount\Services\ProgramPaymentService;
use Headcount\Services\ProgramPricingService;

header('Content-Type: application/json');

$configFile = HC_PROJECT_ROOT . '/config/config.php';
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

$isAuthenticated = PortalAuthMiddleware::isAuthenticated();
$memberId = $isAuthenticated ? PortalAuthMiddleware::getMemberId() : null;

function portal_programs_require_auth(): void
{
    if (!PortalAuthMiddleware::isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in to continue']);
        exit;
    }
}

/**
 * Load a published program by id (public — org derived from the program row).
 */
function portal_program_fetch_published($db, int $programId): ?array
{
    if ($programId <= 0) {
        return null;
    }
    return $db->queryOne(
        "SELECT p.*, pc.name AS category_name FROM programs p
         LEFT JOIN program_categories pc ON pc.id = p.category_id
         WHERE p.id = :id AND p.status = 'published'",
        ['id' => $programId]
    ) ?: null;
}

function portal_program_json_payload(ProgramService $svc, array $p, ?int $memberId, $db, int $orgId): array
{
    $pid = (int) $p['id'];
    $p['banner_image_url'] = portal_program_banner_url($p['banner_image'] ?? '');
    if (!empty($p['title'])) {
        $p['title'] = Utilities::decodeHtmlEntities($p['title']);
    }
    if (!empty($p['category_name'])) {
        $p['category_name'] = Utilities::decodeHtmlEntities($p['category_name']);
    }
    $p['questions'] = $svc->getQuestions($pid);
    $p['weeks'] = $svc->listWeeksWithSessions($pid);
    $p['next_session'] = $svc->getNextSessionDate($pid, $memberId);
    $p['registration'] = $memberId ? $svc->getRegistration($pid, $memberId) : null;
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
    return $p;
}

if ($method === 'GET' && isset($_GET['id']) && $_GET['id'] !== '') {
    $pid = (int) $_GET['id'];
    $p = portal_program_fetch_published($db, $pid);
    if (!$p) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Not found']);
        exit;
    }
    $orgId = (int) ($p['organization_id'] ?? 0);
    echo json_encode([
        'success' => true,
        'program' => portal_program_json_payload($svc, $p, $memberId, $db, $orgId),
    ]);
    exit;
}

$orgId = headcount_resolve_portal_organization_id(
    $isAuthenticated ? PortalAuthMiddleware::getOrganizationId() : null,
    $config,
    $db
);
if (!$orgId) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Organization not configured for portal']);
    exit;
}
$pricingSvc = new ProgramPricingService();

if ($method === 'GET' && ($_GET['action'] ?? '') === 'quote') {
    $pid = (int) ($_GET['program_id'] ?? 0);
    $weekIdsRaw = $_GET['week_ids'] ?? '';
    $weekIds = [];
    if (is_array($weekIdsRaw)) {
        $weekIds = array_map('intval', $weekIdsRaw);
    } elseif (is_string($weekIdsRaw) && $weekIdsRaw !== '') {
        $dec = json_decode($weekIdsRaw, true);
        if (is_array($dec)) {
            $weekIds = array_map('intval', $dec);
        } else {
            $weekIds = array_map('intval', array_filter(explode(',', $weekIdsRaw)));
        }
    }
    $p = portal_program_fetch_published($db, $pid);
    if (!$p) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Not found']);
        exit;
    }
    $allWeeks = $svc->listWeeks($pid);
    $quote = $pricingSvc->quote($p, $weekIds, $allWeeks);
    echo json_encode(['success' => !empty($quote['success']), 'quote' => $quote] + (empty($quote['success']) ? ['message' => $quote['message'] ?? 'Invalid selection'] : []));
    exit;
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'mine') {
    portal_programs_require_auth();
    $memberId = PortalAuthMiddleware::getMemberId();
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
            $m['next_session'] = $svc->getNextSessionDate($pid, $memberId);
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
        $next = $svc->getNextSessionDate((int) $r['id'], $memberId);
        $r['next_session'] = $next;
        $reg = $memberId ? $svc->getRegistration((int) $r['id'], $memberId) : null;
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
    portal_programs_require_auth();
    $memberId = PortalAuthMiddleware::getMemberId();
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
        $weekIds = isset($input['week_ids']) && is_array($input['week_ids'])
            ? array_map('intval', $input['week_ids'])
            : [];
        $answerCheck = $svc->validateRegistrationAnswers($pid, $answers);
        if (empty($answerCheck['success'])) {
            echo json_encode($answerCheck);
            exit;
        }
        $res = $svc->registerFree($pid, $memberId, $answers, $weekIds);
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
        $weekIds = isset($input['week_ids']) && is_array($input['week_ids'])
            ? array_map('intval', $input['week_ids'])
            : [];
        $answerCheck = $svc->validateRegistrationAnswers($pid, $answers);
        if (empty($answerCheck['success'])) {
            echo json_encode($answerCheck);
            exit;
        }
        $pending = $svc->createPendingRegistration($pid, $memberId, $answers, $coupon, $weekIds);
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
