<?php
/**
 * Guest program registration (public — no portal login).
 * GET: program details when allow_guest_registration is enabled.
 * POST: free program registration for guests.
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
use Headcount\Helpers\Security;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\ProgramService;
use Headcount\Services\EmailService;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}
$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');

$db = Database::getInstance();
$svc = new ProgramService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(@file_get_contents('php://input'), true) ?? [];
$input = array_merge($_GET, $_POST, $input);

function guest_program_banner_url($path)
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

function guest_program_row(Database $db, int $programId): ?array
{
    return $db->queryOne(
        "SELECT p.*, pc.name AS category_name FROM programs p
         LEFT JOIN program_categories pc ON pc.id = p.category_id
         WHERE p.id = :id AND p.status = 'published'",
        ['id' => $programId]
    );
}

function guest_program_allowed(array $program): bool
{
    return !empty($program['allow_guest_registration']);
}

$programId = (int) ($input['program_id'] ?? $input['id'] ?? 0);

if ($method === 'GET' && (($input['action'] ?? '') === 'quote' || isset($_GET['action']) && $_GET['action'] === 'quote')) {
    $weekIdsRaw = $input['week_ids'] ?? $_GET['week_ids'] ?? '';
    $weekIds = [];
    if (is_array($weekIdsRaw)) {
        $weekIds = array_map('intval', $weekIdsRaw);
    } elseif (is_string($weekIdsRaw) && $weekIdsRaw !== '') {
        $dec = json_decode($weekIdsRaw, true);
        $weekIds = is_array($dec) ? array_map('intval', $dec) : array_map('intval', array_filter(explode(',', $weekIdsRaw)));
    }
    $p = guest_program_row($db, $programId);
    if (!$p || !guest_program_allowed($p)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Program not found']);
        exit;
    }
    $pricingSvc = new \Headcount\Services\ProgramPricingService();
    $quote = $pricingSvc->quote($p, $weekIds, $svc->listWeeks($programId));
    echo json_encode(['success' => !empty($quote['success']), 'quote' => $quote]);
    exit;
}

if ($method === 'GET') {
    if ($programId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Program ID required']);
        exit;
    }
    $p = guest_program_row($db, $programId);
    if (!$p) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Program not found']);
        exit;
    }
    if (!guest_program_allowed($p)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This program does not allow guest registration. Please log in.']);
        exit;
    }
    if (!empty($p['title'])) {
        $p['title'] = Utilities::decodeHtmlEntities($p['title']);
    }
    if (!empty($p['category_name'])) {
        $p['category_name'] = Utilities::decodeHtmlEntities($p['category_name']);
    }
    $p['banner_image_url'] = guest_program_banner_url($p['banner_image'] ?? '');
    $p['questions'] = $svc->getQuestions($programId);
    $p['weeks'] = $svc->listWeeksWithSessions($programId);
    $p['next_session'] = $svc->getNextSessionDate($programId);
    $orgId = (int) ($p['organization_id'] ?? 0);
    try {
        $orgWaiver = $db->queryOne(
            'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
            ['id' => $orgId]
        );
        $p['waiver'] = headcount_portal_waiver_payload(is_array($orgWaiver) ? $orgWaiver : null);
    } catch (\Throwable $e) {
        $p['waiver'] = headcount_portal_waiver_payload(null);
    }
    unset($p['created_by']);
    echo json_encode(['success' => true, 'program' => $p]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

CsrfMiddleware::verify($input);

$firstName = isset($input['first_name']) ? trim($input['first_name']) : '';
$lastName = isset($input['last_name']) ? trim($input['last_name']) : '';
$email = isset($input['email']) ? trim(strtolower($input['email'])) : '';
$answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];
$weekIds = isset($input['week_ids']) && is_array($input['week_ids']) ? array_map('intval', $input['week_ids']) : [];

if ($programId <= 0 || !$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Program ID and email are required.']);
    exit;
}
if (!$firstName || !$lastName) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'First name and last name are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$p = guest_program_row($db, $programId);
if (!$p) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Program not found']);
    exit;
}
if (!guest_program_allowed($p)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Guest registration is not enabled for this program.']);
    exit;
}

$orgId = (int) ($p['organization_id'] ?? 0);
$orgRow = $db->queryOne(
    'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
    ['id' => $orgId]
);
$waiverErr = headcount_waiver_validation_error(is_array($orgRow) ? $orgRow : null, $input);
if ($waiverErr !== null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $waiverErr]);
    exit;
}

if (($p['pricing_type'] ?? 'free') !== 'free') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'This program requires payment. Use Continue to payment in the guest form.',
    ]);
    exit;
}

$user = $db->queryOne(
    "SELECT id, organization_id, first_name, last_name, email, password_hash FROM users
     WHERE organization_id = :oid AND email = :email AND status != 'deleted'",
    ['oid' => $orgId, 'email' => $email]
);

$isNewUser = false;
if (!$user) {
    $userId = (int) $db->insert('users', [
        'organization_id' => $orgId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => null,
        'password_hash' => null,
        'role' => 'member',
        'status' => 'active',
        'qr_code_secret' => Security::generateToken(32),
        'email_preferences' => json_encode([
            'event_announcements' => true,
            'event_reminders' => true,
            'rsvp_confirmations' => true,
            'payment_receipts' => true,
        ]),
        'communication_preferences' => json_encode([
            'email_enabled' => true,
            'sms_enabled' => false,
        ]),
    ]);
    $user = $db->queryOne('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
    $isNewUser = true;
} else {
    $userId = (int) $user['id'];
    if (trim((string) ($user['first_name'] ?? '')) === '' || trim((string) ($user['last_name'] ?? '')) === '') {
        $db->update('users', $userId, [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }
}

$res = $svc->registerFree($programId, $userId, $answers, $weekIds);
if (empty($res['success'])) {
    http_response_code(400);
    echo json_encode($res);
    exit;
}

$regId = (int) ($res['registration_id'] ?? 0);
if ($regId > 0) {
    headcount_mark_waiver_accepted($db, 'program_registrations', $regId);
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = preg_replace('#/api/portal/.*$#', '', $scriptName);
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($basePath))), '/');
$portalBase = $protocol . '://' . $host . $basePath;
$registerUrl = $portalBase . '/portal/register.php?email=' . urlencode($email);

try {
    $smtp = $config['smtp2go'] ?? [];
    if (!empty($smtp['api_key'])) {
        $emailSvc = new EmailService($smtp);
        $title = Utilities::decodeHtmlEntities($p['title'] ?? 'Program');
        $body = '<p>Hi ' . htmlspecialchars($firstName) . ',</p>'
            . '<p>You are registered for <strong>' . htmlspecialchars($title) . '</strong>.</p>';
        if ($isNewUser) {
            $body .= '<p><a href="' . htmlspecialchars($registerUrl) . '">Complete your account</a> to set a password and access the member portal.</p>';
        }
        $emailSvc->sendEmail(
            $email,
            'Registration confirmed: ' . $title,
            $body,
            $orgId,
            ['email_type' => 'program_registration', 'program_id' => $programId, 'user_id' => $userId]
        );
    }
} catch (\Throwable $e) {
    error_log('guest-program-register email: ' . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'registration_id' => $regId,
    'is_new_user' => $isNewUser,
    'complete_account_url' => $isNewUser ? $registerUrl : null,
]);
