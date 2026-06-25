<?php
/**
 * Guest checkout for paid programs (public — no portal login).
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
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\ProgramService;
use Headcount\Services\ProgramPaymentService;

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance();
$svc = new ProgramService();
$input = json_decode(@file_get_contents('php://input'), true) ?? [];
$input = array_merge($_POST, $input);

CsrfMiddleware::verify($input);

$programId = (int) ($input['program_id'] ?? 0);
$firstName = isset($input['first_name']) ? trim($input['first_name']) : '';
$lastName = isset($input['last_name']) ? trim($input['last_name']) : '';
$email = isset($input['email']) ? trim(strtolower($input['email'])) : '';
$answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];
$weekIds = isset($input['week_ids']) && is_array($input['week_ids']) ? array_map('intval', $input['week_ids']) : [];
$coupon = $input['coupon_code'] ?? null;

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

$p = $db->queryOne(
    "SELECT * FROM programs WHERE id = :id AND status = 'published'",
    ['id' => $programId]
);
if (!$p) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Program not found']);
    exit;
}
if (empty($p['allow_guest_registration'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Guest registration is not enabled for this program.']);
    exit;
}
if (($p['pricing_type'] ?? 'free') === 'free') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This program is free. Use the guest register endpoint.']);
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

$user = $db->queryOne(
    "SELECT id, first_name, last_name, email, password_hash FROM users
     WHERE organization_id = :oid AND email = :email AND status != 'deleted'",
    ['oid' => $orgId, 'email' => $email]
);

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
} else {
    $userId = (int) $user['id'];
    if (trim((string) ($user['first_name'] ?? '')) === '' || trim((string) ($user['last_name'] ?? '')) === '') {
        $db->update('users', $userId, [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }
}

$answerCheck = $svc->validateRegistrationAnswers($programId, $answers);
if (empty($answerCheck['success'])) {
    http_response_code(400);
    echo json_encode($answerCheck);
    exit;
}

$pending = $svc->createPendingRegistration($programId, $userId, $answers, $coupon, $weekIds);
if (empty($pending['success'])) {
    http_response_code(400);
    echo json_encode($pending);
    exit;
}

$regId = (int) $pending['registration_id'];
headcount_mark_waiver_accepted($db, 'program_registrations', $regId);

$pay = new ProgramPaymentService();
$res = $pay->createCheckoutSession($programId, $userId, $regId, $coupon);
if (empty($res['success'])) {
    http_response_code(400);
}
echo json_encode($res);
