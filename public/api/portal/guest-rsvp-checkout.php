<?php

/**
 * Guest checkout for paid events (public — no portal login).
 * Creates or finds a user, starts Stripe Checkout; webhook completes RSVP.
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
use Headcount\Services\PortalPaymentService;
use Headcount\Services\PotluckCategoryService;
use Headcount\Services\EventEligibilityService;
use Headcount\Services\RSVPService;
use Headcount\Services\EventVisibilityService;
use Headcount\Services\EventTicketSelectionService;

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance();
$input = json_decode(@file_get_contents('php://input'), true) ?? [];
$input = array_merge($_POST, $input);

CsrfMiddleware::verify($input);

$eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;
$firstName = isset($input['first_name']) ? trim($input['first_name']) : '';
$lastName = isset($input['last_name']) ? trim($input['last_name']) : '';
$email = isset($input['email']) ? trim(strtolower($input['email'])) : '';
$guestCount = isset($input['guest_count']) ? max(0, min(10, (int) $input['guest_count'])) : 0;
$questionAnswers = isset($input['question_answers']) && is_array($input['question_answers']) ? $input['question_answers'] : [];

$ticketsRaw = isset($input['tickets']) && is_array($input['tickets']) ? $input['tickets'] : [];
$tickets = [];
foreach ($ticketsRaw as $t) {
    $typeId = (int) ($t['ticket_type_id'] ?? 0);
    $qty = (int) ($t['quantity'] ?? 0);
    if ($typeId <= 0 || $qty <= 0) {
        continue;
    }
    $tickets[] = ['ticket_type_id' => $typeId, 'quantity' => $qty];
}

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
    echo json_encode(['success' => false, 'message' => 'This event does not accept public guest checkout.']);
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
    echo json_encode(['success' => false, 'message' => 'This event does not allow guest RSVP. Please log in to register.']);
    exit;
}

$eligGuestCheckout = new EventEligibilityService($db);
$guestEligibility = $eligGuestCheckout->validateGuestSubmission($event, [
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
$typeMap = $hasNamedTicketTypes ? EventTicketSelectionService::loadTypeMapForEvent($db, $eventId) : [];
$orgTzGuest = EventTicketSelectionService::orgTimezoneForEvent($db, $event);

if (!EventTicketSelectionService::eventSupportsPaidCheckout($db, $event)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This event is free. Submit a guest RSVP without payment.']);
    exit;
}

if ($hasNamedTicketTypes && $tickets === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select at least one ticket.']);
    exit;
}

if ($tickets !== []) {
    $rulesCheck = EventTicketSelectionService::validateSelectionRules($tickets, $typeMap, $orgTzGuest);
    if (!$rulesCheck['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $rulesCheck['message'] ?? 'Invalid ticket selection.']);
        exit;
    }
    $quoteCheckout = EventTicketSelectionService::quoteSelection($tickets, $typeMap);
    if ($quoteCheckout['totalAmount'] <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Your ticket selection is free. Submit RSVP without payment instead.',
        ]);
        exit;
    }
} elseif ($hasPaidTicketTypes && $ticketPrice <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select at least one ticket.']);
    exit;
}

// Required questions (visible only)
try {
    $requiredRows = $db->query(
        "SELECT id, depends_on_question_id, depends_on_value FROM event_questions WHERE event_id = :event_id AND is_required = 1",
        ['event_id' => $eventId]
    );
    foreach ($requiredRows as $r) {
        $depId = isset($r['depends_on_question_id']) ? (int) $r['depends_on_question_id'] : null;
        $depVal = isset($r['depends_on_value']) ? trim((string) $r['depends_on_value']) : null;
        $visible = true;
        if ($depId && $depVal !== null && $depVal !== '') {
            $depAnswer = $questionAnswers[$depId] ?? $questionAnswers[(string) $depId] ?? null;
            $depStr = is_array($depAnswer) ? implode(',', $depAnswer) : trim((string) $depAnswer);
            if ($depVal === '__any__') {
                $visible = $depStr !== '';
            } else {
                $visible = $depStr === $depVal || (is_array($depAnswer) && in_array($depVal, $depAnswer, true));
            }
        }
        if (!$visible) {
            continue;
        }
        $qid = $r['id'];
        $val = $questionAnswers[$qid] ?? $questionAnswers[(string) $qid] ?? '';
        $isEmpty = is_array($val) ? empty($val) : (trim((string) $val) === '');
        if ($isEmpty) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please answer all required questions.']);
            exit;
        }
    }
} catch (\Exception $e) {
    // ignore
}

$hasExtPotluckCheckout = $db->hasColumn('rsvps', 'potluck_quantity')
    && $db->hasColumn('rsvps', 'potluck_serving_side')
    && $db->hasColumn('rsvps', 'potluck_party_adults')
    && $db->hasColumn('rsvps', 'potluck_party_children');
$potluckNormCheckout = null;
if (!empty($event['is_potluck'])) {
    $requirePotluckDishCheckout = PotluckCategoryService::requiresPotluckDishCategoryFromRequest($input);
    $potluckAllowedCheckout = PotluckCategoryService::parsePotluckAllowedSlugsFromEvent($event);
    $potluckNormCheckout = PotluckCategoryService::normalizePotluckSignup($input, $hasExtPotluckCheckout, $requirePotluckDishCheckout, $potluckAllowedCheckout);
    if (!$potluckNormCheckout['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $potluckNormCheckout['error']]);
        exit;
    }
    if (empty($tickets)
        && $potluckNormCheckout['party_adults'] !== null
        && $potluckNormCheckout['party_children'] !== null) {
        $guestCount = max(0, min(10, (int) $potluckNormCheckout['party_adults'] + (int) $potluckNormCheckout['party_children'] - 1));
    }
}

$organizationId = (int) $event['organization_id'];

$seriesRootId = EventSeriesHelper::getSeriesRootId($db, $eventId);
$sessionMode = EventSeriesHelper::getSessionRegistrationMode($db, $eventId);
$seriesIds = ($seriesRootId && EventSeriesHelper::columnExists($db))
    ? EventSeriesHelper::getPublishedSeriesEventIds($db, $seriesRootId)
    : [];
$targetEventIds = [$eventId];
if ($seriesRootId && count($seriesIds) > 1 && $sessionMode === EventSeriesHelper::MODE_ALL_SESSIONS) {
    $targetEventIds = $seriesIds;
}

$headsForCapacity = 1 + $guestCount;
if (!empty($tickets)) {
    $headsForCapacity = 0;
    foreach ($tickets as $t) {
        $headsForCapacity += (int) ($t['quantity'] ?? 0);
    }
    if ($headsForCapacity < 1) {
        $headsForCapacity = 1;
    }
} elseif (!empty($event['is_potluck']) && $potluckNormCheckout && $potluckNormCheckout['ok']
    && $potluckNormCheckout['party_adults'] !== null && $potluckNormCheckout['party_children'] !== null) {
    $headsForCapacity = (int) $potluckNormCheckout['party_adults'] + (int) $potluckNormCheckout['party_children'];
}

$user = $db->queryOne(
    "SELECT id, organization_id, first_name, last_name, email, password_hash FROM users WHERE organization_id = :oid AND email = :email AND status != 'deleted'",
    ['oid' => $organizationId, 'email' => $email]
);

$userIdForChecks = $user ? (int) $user['id'] : null;
if ($userIdForChecks && count($seriesIds) > 1 && $sessionMode === EventSeriesHelper::MODE_CHOOSE_ONE) {
    EventSeriesHelper::clearYesRsvpsExcept($db, [$userIdForChecks], $seriesIds, $eventId);
}

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
    $headRow = $db->queryOne(
        "SELECT COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) as total FROM rsvps WHERE event_id = :eid AND status = 'yes'",
        ['eid' => $tid]
    );
    $totalHeadCount = (int) ($headRow['total'] ?? 0);
    $existingForTid = null;
    if ($userIdForChecks) {
        $existingForTid = $db->queryOne(
            "SELECT id, COALESCE(guest_count, 0) as guest_count FROM rsvps WHERE event_id = :eid AND user_id = :uid",
            ['eid' => $tid, 'uid' => $userIdForChecks]
        );
    }
    if ($existingForTid) {
        $existingGuestCount = (int) ($existingForTid['guest_count'] ?? 0);
        $currentUsed = $totalHeadCount - (1 + $existingGuestCount) + $headsForCapacity;
    } else {
        $currentUsed = $totalHeadCount + $headsForCapacity;
    }
    if ($currentUsed > (int) $evCap['capacity']) {
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
            'payment_receipts' => true,
        ]),
        'communication_preferences' => json_encode([
            'email_enabled' => true,
            'sms_enabled' => false,
        ]),
    ];
    if ($guestEligibilityDob !== null && $db->hasColumn('users', 'date_of_birth')) {
        $newUserRow['date_of_birth'] = $guestEligibilityDob;
    }
    if ($guestEligibilityGender !== null && $db->hasColumn('users', 'gender')) {
        $newUserRow['gender'] = $guestEligibilityGender;
    }
    $userId = (int) $db->insert('users', $newUserRow);
    $isNewUser = true;
} else {
    $userId = (int) $user['id'];
    $eligGuestCheckout->persistGuestProfileFields($userId, $guestEligibilityDob, $guestEligibilityGender);
}

$pendingGuestCount = !empty($tickets) ? 0 : $guestCount;
$pending = [
    'guest_count' => $pendingGuestCount,
    'target_event_ids' => $targetEventIds,
    'question_answers' => $questionAnswers,
    'is_new_user' => $isNewUser,
    'waiver_accepted' => true,
];
if (!empty($tickets)) {
    $pending['tickets'] = $tickets;
}
if ($potluckNormCheckout !== null && $potluckNormCheckout['ok']) {
    if (!empty($potluckNormCheckout['slug'])) {
        $pending['potluck_category'] = $potluckNormCheckout['slug'];
        $pending['potluck_item_note'] = $potluckNormCheckout['note'];
        if ($potluckNormCheckout['quantity'] !== null) {
            $pending['potluck_quantity'] = (int) $potluckNormCheckout['quantity'];
        }
        if ($potluckNormCheckout['serving_side'] !== null) {
            $pending['potluck_serving_side'] = (string) $potluckNormCheckout['serving_side'];
        }
    }
    if ($potluckNormCheckout['party_adults'] !== null) {
        $pending['potluck_party_adults'] = (int) $potluckNormCheckout['party_adults'];
    }
    if ($potluckNormCheckout['party_children'] !== null) {
        $pending['potluck_party_children'] = (int) $potluckNormCheckout['party_children'];
    }
}

try {
    $paymentService = new PortalPaymentService();
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('guest-rsvp-checkout PortalPaymentService: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Payment service unavailable. Please try again.']);
    exit;
}

try {
    $result = $paymentService->createCheckoutSession(
        $eventId,
        $userId,
        $pendingGuestCount,
        $tickets,
        $pending
    );
} catch (\Throwable $e) {
    error_log('guest-rsvp-checkout: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'Checkout failed. Ensure Stripe is configured.',
    ]);
    exit;
}

if (!empty($result['success']) && !empty($result['checkout_url'])) {
    echo json_encode($result);
    exit;
}

http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => $result['message'] ?? 'Could not start checkout.',
]);
