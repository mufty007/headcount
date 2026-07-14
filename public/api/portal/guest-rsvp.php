<?php

/**
 * Guest RSVP API (public - no authentication required)
 * Allows non-members to RSVP once; creates user and sends "complete your account" email.
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
use Headcount\Services\EventSeriesHelper;
use Headcount\Services\PortalEmailService;
use Headcount\Services\PotluckCategoryService;
use Headcount\Services\EventEligibilityService;
use Headcount\Services\EventTicketSelectionService;
use Headcount\Services\RSVPService;
use Headcount\Services\EventVisibilityService;

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

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

$db = Database::getInstance();
if (!isset($input)) {
    $input = json_decode(@file_get_contents('php://input'), true) ?? [];
}
$input = array_merge($_POST, $input);

CsrfMiddleware::verify($input);

$eventId = isset($input['event_id']) ? (int)$input['event_id'] : 0;
$firstName = isset($input['first_name']) ? trim($input['first_name']) : '';
$lastName = isset($input['last_name']) ? trim($input['last_name']) : '';
$email = isset($input['email']) ? trim(strtolower($input['email'])) : '';
$guestCount = isset($input['guest_count']) ? max(0, min(10, (int)$input['guest_count'])) : 0;
$questionAnswers = isset($input['question_answers']) && is_array($input['question_answers']) ? $input['question_answers'] : [];
$normalizeAnswerForStorage = static function ($raw) {
    if (is_array($raw)) {
        $vals = [];
        foreach ($raw as $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $s = trim((string) $v);
            if ($s !== '') {
                $vals[] = $s;
            }
        }
        $vals = array_values(array_unique($vals));
        if (empty($vals)) {
            return null;
        }
        return json_encode($vals);
    }
    if (!is_scalar($raw)) {
        return null;
    }
    $s = trim((string) $raw);
    return $s === '' ? null : $s;
};

if (!$eventId || !$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Event ID and email are required.']);
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

$event = $db->queryOne(
    "SELECT * FROM events WHERE id = :id AND status = 'published'",
    ['id' => $eventId]
);
if (!$event) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Event not found or not available for RSVP.']);
    exit;
}
$event = EventSeriesHelper::mergeSeriesParentPolicyFields($db, $event);
if (!EventVisibilityService::guestRsvpAllowed($event)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This event does not accept public guest RSVP.']);
    exit;
}

$orgRow = $db->queryOne(
    'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
    ['id' => (int) ($event['organization_id'] ?? 0)]
);
$waiverErr = headcount_waiver_validation_error(is_array($orgRow) ? $orgRow : null, $input);
if ($waiverErr !== null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $waiverErr]);
    exit;
}

$rsvpDeadline = new RSVPService();
if ($rsvpDeadline->isRegistrationDeadlinePassed($event)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Online RSVP is closed for this event.']);
    exit;
}

$allowGuest = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM events");
    if (in_array('allow_guest_rsvp', array_column($cols, 'Field'))) {
        $allowGuest = !empty($event['allow_guest_rsvp']);
    }
} catch (\Exception $e) { /* ignore */ }
if (!$allowGuest) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This event does not allow guest RSVP. Please log in to RSVP.']);
    exit;
}

$eligibilityGuest = new EventEligibilityService($db);
$guestEligibility = $eligibilityGuest->validateGuestSubmission($event, [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'date_of_birth' => $input['date_of_birth'] ?? null,
    'gender' => $input['gender'] ?? null,
]);
if (!$guestEligibility['ok']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $guestEligibility['message'] ?? 'You do not meet the requirements for this event.',
    ]);
    exit;
}
$guestEligibilityDob = $guestEligibility['date_of_birth'];
$guestEligibilityGender = $guestEligibility['gender'];

$ticketPrice = (float) ($event['ticket_price'] ?? 0);
$ticketFlags = EventTicketSelectionService::eventTicketTypeFlags($db, $eventId, $ticketPrice);
$hasNamedTicketTypes = $ticketFlags['has_named_types'];
$hasPaidTicketTypes = $ticketFlags['has_paid_types'];
$tickets = EventTicketSelectionService::parseTicketsFromRequest($input);
$typeMap = $hasNamedTicketTypes ? EventTicketSelectionService::loadTypeMapForEvent($db, $eventId) : [];
$quote = EventTicketSelectionService::quoteSelection($tickets, $typeMap);
$orgTzGuest = EventTicketSelectionService::orgTimezoneForEvent($db, $event);

if ($ticketPrice > 0 && !$hasNamedTicketTypes) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'This event requires payment. Use Continue to payment in the guest form, or log in to register.',
    ]);
    exit;
}

if ($hasNamedTicketTypes) {
    if ($tickets === []) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please select at least one ticket.']);
        exit;
    }
    $rulesCheck = EventTicketSelectionService::validateSelectionRules($tickets, $typeMap, $orgTzGuest);
    if (!$rulesCheck['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $rulesCheck['message'] ?? 'Invalid ticket selection.']);
        exit;
    }
    if ($quote['totalAmount'] > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'This selection requires payment. Choose Continue to payment, or select free ticket options only.',
        ]);
        exit;
    }
} elseif ($hasPaidTicketTypes) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'This event requires payment. Use Continue to payment in the guest form, or log in to register.',
    ]);
    exit;
}

// Validate required questions: only when visible (dependency condition met)
try {
    $requiredRows = $db->query(
        "SELECT id, depends_on_question_id, depends_on_value FROM event_questions WHERE event_id = :event_id AND is_required = 1",
        ['event_id' => $eventId]
    );
    foreach ($requiredRows as $r) {
        $depId = isset($r['depends_on_question_id']) ? (int)$r['depends_on_question_id'] : null;
        $depVal = isset($r['depends_on_value']) ? trim((string)$r['depends_on_value']) : null;
        $visible = true;
        if ($depId && $depVal !== null && $depVal !== '') {
            $depAnswer = $questionAnswers[$depId] ?? $questionAnswers[(string)$depId] ?? null;
            $depStr = is_array($depAnswer) ? implode(',', $depAnswer) : trim((string)$depAnswer);
            if ($depVal === '__any__') {
                $visible = $depStr !== '';
            } else {
                $visible = $depStr === $depVal || (is_array($depAnswer) && in_array($depVal, $depAnswer, true));
            }
        }
        if (!$visible) continue;
        $qid = $r['id'];
        $val = $questionAnswers[$qid] ?? $questionAnswers[(string)$qid] ?? '';
        $isEmpty = ($normalizeAnswerForStorage($val) === null);
        if ($isEmpty) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please answer all required questions.']);
            exit;
        }
    }
} catch (\Exception $e) {
    // event_questions table or columns may not exist
}

$hasExtPotluckGuest = $db->hasColumn('rsvps', 'potluck_quantity')
    && $db->hasColumn('rsvps', 'potluck_serving_side')
    && $db->hasColumn('rsvps', 'potluck_party_adults')
    && $db->hasColumn('rsvps', 'potluck_party_children');
$potluckNorm = null;
if (!empty($event['is_potluck'])) {
    $requirePotluckDish = PotluckCategoryService::requiresPotluckDishCategoryFromRequest($input);
    $potluckAllowedGuest = PotluckCategoryService::parsePotluckAllowedSlugsFromEvent($event);
    $potluckNorm = PotluckCategoryService::normalizePotluckSignup($input, $hasExtPotluckGuest, $requirePotluckDish, $potluckAllowedGuest);
    if (!$potluckNorm['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $potluckNorm['error']]);
        exit;
    }
    if ($potluckNorm['party_adults'] !== null && $potluckNorm['party_children'] !== null && $tickets === []) {
        $guestCount = max(0, min(10, (int) $potluckNorm['party_adults'] + (int) $potluckNorm['party_children'] - 1));
    }
}

if ($tickets !== []) {
    $guestCount = 0;
}

$organizationId = (int)$event['organization_id'];

$seriesRootId = EventSeriesHelper::getSeriesRootId($db, $eventId);
$sessionMode = EventSeriesHelper::getSessionRegistrationMode($db, $eventId);
$seriesIds = ($seriesRootId && EventSeriesHelper::columnExists($db))
    ? EventSeriesHelper::getPublishedSeriesEventIds($db, $seriesRootId)
    : [];
$targetEventIds = [$eventId];
if ($seriesRootId && count($seriesIds) > 1 && $sessionMode === EventSeriesHelper::MODE_ALL_SESSIONS) {
    $targetEventIds = $seriesIds;
}

// Get user by email first (for capacity check when updating existing RSVP)
$user = $db->queryOne(
    "SELECT id, organization_id, first_name, last_name, email, password_hash FROM users WHERE organization_id = :oid AND email = :email AND status != 'deleted'",
    ['oid' => $organizationId, 'email' => $email]
);

$userIdForChecks = $user ? (int) $user['id'] : null;
if ($userIdForChecks && count($seriesIds) > 1 && $sessionMode === EventSeriesHelper::MODE_CHOOSE_ONE) {
    EventSeriesHelper::clearYesRsvpsExcept($db, [$userIdForChecks], $seriesIds, $eventId);
}

// Capacity check per session (head count: member + guests)
foreach ($targetEventIds as $tid) {
    $tid = (int) $tid;
    $evCap = $db->queryOne(
        "SELECT id, capacity, title FROM events WHERE id = :id AND status = 'published'",
        ['id' => $tid]
    );
    if (!$evCap) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'One or more sessions are no longer available for RSVP.']);
        exit;
    }
    if (empty($evCap['capacity'])) {
        continue;
    }
    $headsForCapacity = EventTicketSelectionService::headsForCapacity(
        $tickets,
        $guestCount,
        $potluckNorm,
        !empty($event['is_potluck'])
    );
    $headRow = $db->queryOne(
        "SELECT COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) as total FROM rsvps WHERE event_id = :eid AND status = 'yes'",
        ['eid' => $tid]
    );
    $totalHeadCount = (int)($headRow['total'] ?? 0);
    $existingForTid = null;
    if ($userIdForChecks) {
        $existingForTid = $db->queryOne(
            "SELECT id, COALESCE(guest_count, 0) as guest_count FROM rsvps WHERE event_id = :eid AND user_id = :uid",
            ['eid' => $tid, 'uid' => $userIdForChecks]
        );
    }
    if ($existingForTid) {
        $existingGuestCount = (int)($existingForTid['guest_count'] ?? 0);
        $currentUsed = $totalHeadCount - (1 + $existingGuestCount) + $headsForCapacity;
    } else {
        $currentUsed = $totalHeadCount + $headsForCapacity;
    }
    if ($currentUsed > (int)$evCap['capacity']) {
        http_response_code(400);
        $msg = 'Not enough spots available for you and your guests.';
        if (count($targetEventIds) > 1) {
            $msg .= ' (' . ($evCap['title'] ?? 'Session') . ')';
        }
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
}

$isNewUser = false;
if (!$user) {
    $newUserRow = [
        'organization_id' => $organizationId,
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
            'payment_receipts' => true
        ]),
        'communication_preferences' => json_encode([
            'email_enabled' => true,
            'sms_enabled' => false
        ])
    ];
    if ($guestEligibilityDob !== null && $db->hasColumn('users', 'date_of_birth')) {
        $newUserRow['date_of_birth'] = $guestEligibilityDob;
    }
    if ($guestEligibilityGender !== null && $db->hasColumn('users', 'gender')) {
        $newUserRow['gender'] = $guestEligibilityGender;
    }
    $userId = (int) $db->insert('users', $newUserRow);
    $user = $db->queryOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
    $isNewUser = true;
} else {
    $userId = (int) $user['id'];
    $eligibilityGuest->persistGuestProfileFields($userId, $guestEligibilityDob, $guestEligibilityGender);
}

$notes = $guestCount > 0 ? "Guests: {$guestCount}" : null;
$guestCols = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM rsvps");
    $guestCols = in_array('guest_count', array_column($cols, 'Field'));
} catch (\Exception $e) { /* ignore */ }

$primaryRsvpId = null;
foreach ($targetEventIds as $tid) {
    $tid = (int) $tid;
    $evRow = $db->queryOne(
        "SELECT id FROM events WHERE id = :id AND status = 'published'",
        ['id' => $tid]
    );
    if (!$evRow) {
        continue;
    }
    $insertPayload = [
        'event_id' => $tid,
        'user_id' => $userId,
        'status' => 'yes',
        'notes' => $notes
    ];
    if ($guestCols) {
        $insertPayload['guest_count'] = $guestCount;
    }
    $existingForSession = $db->queryOne(
        "SELECT id FROM rsvps WHERE event_id = :eid AND user_id = :uid",
        ['eid' => $tid, 'uid' => $userId]
    );
    if ($existingForSession) {
        $db->update('rsvps', $existingForSession['id'], array_diff_key($insertPayload, ['event_id' => 1, 'user_id' => 1]));
        $rsvpIdThis = (int) $existingForSession['id'];
    } else {
        $rsvpIdThis = (int) $db->insert('rsvps', $insertPayload);
    }
    if ($tid === $eventId) {
        $primaryRsvpId = $rsvpIdThis;
    }
    if ($tickets !== [] && $typeMap !== []) {
        EventTicketSelectionService::persistForRsvp($db, $rsvpIdThis, $tickets, $typeMap);
    }
}

$rsvpId = $primaryRsvpId;
if (!$rsvpId) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not complete RSVP. Please try again.']);
    exit;
}

$rsvp = $db->queryOne("SELECT * FROM rsvps WHERE id = :id", ['id' => $rsvpId]);
headcount_mark_waiver_accepted($db, 'rsvps', (int) $rsvpId);

if (!empty($questionAnswers)) {
    try {
        foreach ($questionAnswers as $qId => $answerText) {
            $qId = (int)$qId;
            if ($qId <= 0) continue;
            $stored = $normalizeAnswerForStorage($answerText);
            if ($stored === null) continue;
            $db->execute(
                "INSERT INTO rsvp_question_answers (rsvp_id, question_id, answer_text) VALUES (:rsvp_id, :question_id, :answer_text)
                 ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text)",
                ['rsvp_id' => $rsvpId, 'question_id' => $qId, 'answer_text' => $stored]
            );
        }
    } catch (\Exception $e) {
        error_log("Guest RSVP question_answers error: " . $e->getMessage());
    }
}

if ($potluckNorm !== null && $potluckNorm['ok']) {
    try {
        foreach ($targetEventIds as $tid) {
            $tid = (int) $tid;
            $r2 = $db->queryOne(
                "SELECT id FROM rsvps WHERE event_id = :eid AND user_id = :uid AND status = 'yes'",
                ['eid' => $tid, 'uid' => $userId]
            );
            $e2 = $db->queryOne('SELECT * FROM events WHERE id = ?', [$tid]);
            if ($r2 && $e2 && !empty($e2['is_potluck'])) {
                $potluckApply = PotluckCategoryService::applyPayloadFromNormalization($potluckNorm);
                PotluckCategoryService::applyPotluckState(
                    $db,
                    $e2,
                    (int) $r2['id'],
                    'yes',
                    $potluckApply,
                    $potluckApply !== null
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('Guest RSVP potluck apply: ' . $e->getMessage());
    }
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = preg_replace('#/api/portal/.*$#', '', $scriptName);
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($basePath))), '/');
$portalBase = $protocol . '://' . $host . $basePath;
$registerUrl = $portalBase . '/portal/register.php?email=' . urlencode($email);

try {
    $org = $db->queryOne(
        "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
        [$organizationId]
    );
    $orgConfig = null;
    if ($org && !empty($org['smtp_from_email'])) {
        $apiKey = null;
        if (!empty($org['smtp_api_key'])) $apiKey = base64_decode($org['smtp_api_key'], true);
        if (($apiKey === false || $apiKey === '') && !empty($org['smtp_api_key_encrypted']) && !empty($config['security']['encryption_key'])) {
            $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $config['security']['encryption_key']);
        }
        if (!empty($apiKey)) {
            $orgConfig = [
                'api_key' => $apiKey,
                'from_email' => $org['smtp_from_email'],
                'from_name' => $org['smtp_from_name'] ?? null,
                'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
            ];
        }
    }
    if (!$orgConfig && !empty($config['smtp2go'])) $orgConfig = $config['smtp2go'];
    if ($orgConfig) {
        if (empty($user['organization_id'])) {
            $user['organization_id'] = $organizationId;
        }
        $emailService = new PortalEmailService($orgConfig);
        $emailService->sendGuestRSVPConfirmation($rsvp, $event, $user, $isNewUser ? $registerUrl : null);
    }
} catch (\Exception $e) {
    error_log("Guest RSVP email error: " . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'message' => 'You are registered for this event.',
    'rsvp_id' => $rsvpId,
    'complete_account_sent' => $isNewUser
]);
