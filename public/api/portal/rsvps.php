<?php

/**
 * Portal RSVPs API
 * Handles RSVP operations for members (requires authentication)
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Services\EventSeriesHelper;
use Headcount\Services\PortalEmailService;
use Headcount\Services\PotluckCategoryService;
use Headcount\Services\RSVPService;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Set JSON header
header('Content-Type: application/json');

// Require authentication
PortalAuthMiddleware::requireAuth();

$memberId = PortalAuthMiddleware::getMemberId();
$organizationId = PortalAuthMiddleware::getOrganizationId();

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract action/ID from path
// Remove base path and /api/portal/rsvps from the path to get the action
$pathSegments = explode('/', trim($path, '/'));
// Find 'rsvps' in the path and get everything after it
$rsvpsIndex = -1;
for ($i = 0; $i < count($pathSegments); $i++) {
    if ($pathSegments[$i] === 'rsvps') {
        $rsvpsIndex = $i;
        break;
    }
}

$action = '';
$rsvpId = null;

if ($rsvpsIndex >= 0 && $rsvpsIndex < count($pathSegments) - 1) {
    // There's something after 'rsvps' in the path
    $action = $pathSegments[$rsvpsIndex + 1] ?? '';
    if (is_numeric($action)) {
        $rsvpId = (int)$action;
        $action = '';
    }
} else {
    // No action after 'rsvps', this is the base endpoint
    $action = '';
}

// Get input data (when routed from index.php, $data is already set and php://input is consumed)
if (!isset($input)) {
    $input = @json_decode(file_get_contents('php://input'), true) ?? [];
}
if (!isset($data)) {
    $data = array_merge($_POST, $input);
}
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

$rsvpService = new RSVPService();
$db = Database::getInstance();

/**
 * Get organization SMTP config from database for sending emails.
 * Uses the same org-level settings as Admin "Send test email" and event announcements.
 * Returns config array for EmailService/PortalEmailService or null if not configured.
 */
$getOrgEmailConfig = function ($organizationId) use ($db, $config) {
    $org = $db->queryOne(
        "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
        [$organizationId]
    );
    if (!$org || empty($org['smtp_from_email'])) {
        return null;
    }
    $apiKey = null;
    if (!empty($org['smtp_api_key'])) {
        $apiKey = base64_decode($org['smtp_api_key'], true);
    }
    if (($apiKey === false || empty($apiKey)) && !empty($org['smtp_api_key_encrypted'])) {
        $encKey = $config['security']['encryption_key'] ?? null;
        if ($encKey) {
            $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $encKey);
        }
    }
    if (empty($apiKey)) {
        return null;
    }
    return [
        'api_key' => $apiKey,
        'from_email' => $org['smtp_from_email'],
        'from_name' => $org['smtp_from_name'] ?? null,
        'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
    ];
};

try {
    // GET /api/portal/rsvps/my - Get member's RSVPs (with checked_in and payment for refund eligibility)
    if ($action === 'my' && $method === 'GET') {
        $rsvps = $rsvpService->getMemberRSVPs($memberId);
        $eventIds = array_unique(array_column($rsvps, 'event_id'));
        $checkedIn = [];
        $payments = [];
        if (!empty($eventIds)) {
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $att = $db->query(
                "SELECT event_id, 1 as checked_in FROM attendance WHERE user_id = ? AND event_id IN ($placeholders) AND checked_in_at IS NOT NULL",
                array_merge([$memberId], $eventIds)
            );
            foreach ($att as $a) {
                $checkedIn[(int)$a['event_id']] = true;
            }
            $payRows = $db->query(
                "SELECT event_id, id as payment_id, amount as payment_amount FROM payments WHERE user_id = ? AND event_id IN ($placeholders) AND status = 'paid'",
                array_merge([$memberId], $eventIds)
            );
            foreach ($payRows as $p) {
                $payments[(int)$p['event_id']] = ['payment_id' => (int)$p['payment_id'], 'payment_amount' => (float)$p['payment_amount']];
            }
        }
        foreach ($rsvps as &$r) {
            $eid = (int)$r['event_id'];
            $r['checked_in'] = !empty($checkedIn[$eid]);
            $r['payment_id'] = $payments[$eid]['payment_id'] ?? null;
            $r['payment_amount'] = $payments[$eid]['payment_amount'] ?? null;
        }
        unset($r);
        echo json_encode([
            'success' => true,
            'rsvps' => $rsvps,
            'count' => count($rsvps)
        ]);
        exit;
    }

    // GET /api/portal/rsvps/event/{event_id} - Get RSVPs for event
    if ($action === 'event' && $method === 'GET') {
        $eventId = $_GET['event_id'] ?? null;
        
        if (!$eventId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event ID is required']);
            exit;
        }

        // Get RSVPs for this event (member can see their own RSVP)
        $rsvps = $db->query(
            "SELECT r.*, u.first_name, u.last_name, u.email
             FROM rsvps r
             JOIN users u ON r.user_id = u.id
             WHERE r.event_id = :event_id
             ORDER BY r.created_at DESC",
            ['event_id' => $eventId]
        );

        echo json_encode([
            'success' => true,
            'rsvps' => $rsvps
        ]);
        exit;
    }

    // POST /api/portal/rsvps - Create RSVP
    if (empty($action) && $method === 'POST') {
        // Verify CSRF - Pass $data since we already read php://input
        CsrfMiddleware::verify($data);
        
        $eventId = $data['event_id'] ?? 0;
        $guests = isset($data['guests']) ? (int)$data['guests'] : 0;
        $familyMemberIds = isset($data['family_member_ids']) && is_array($data['family_member_ids']) 
            ? array_map('intval', $data['family_member_ids']) 
            : [];

        if (empty($eventId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event ID is required']);
            exit;
        }

        $orgRow = $db->queryOne(
            'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
            ['id' => $organizationId]
        );
        $waiverErr = headcount_waiver_validation_error(is_array($orgRow) ? $orgRow : null, $data);
        if ($waiverErr !== null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $waiverErr]);
            exit;
        }

        // Validate family members belong to requesting user
        if (!empty($familyMemberIds)) {
            $placeholders = [];
            $params = ['user_id' => $memberId];
            foreach ($familyMemberIds as $index => $fmId) {
                $key = 'fm_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = (int)$fmId;
            }
            $placeholdersStr = implode(',', $placeholders);
            
            $validFamilyMembers = $db->query(
                "SELECT id FROM family_members 
                 WHERE id IN ($placeholdersStr) AND parent_user_id = :user_id",
                $params
            );
            
            if (count($validFamilyMembers) !== count($familyMemberIds)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid family member(s) selected']);
                exit;
            }
        }

        $questionAnswers = isset($data['question_answers']) && is_array($data['question_answers']) ? $data['question_answers'] : [];
        // Required questions are enforced only when visible (dependency condition met)
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
            // event_questions table may not exist or columns may not exist
        }

        $eventPotluckRow = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );
        if (is_array($eventPotluckRow)) {
            $eventPotluckRow = EventSeriesHelper::mergeSeriesParentPolicyFields($db, $eventPotluckRow);
        }
        $hasExtPotluckCols = $db->hasColumn('rsvps', 'potluck_quantity')
            && $db->hasColumn('rsvps', 'potluck_serving_side')
            && $db->hasColumn('rsvps', 'potluck_party_adults')
            && $db->hasColumn('rsvps', 'potluck_party_children');
        $potluckNorm = null;
        if ($eventPotluckRow && !empty($eventPotluckRow['is_potluck'])) {
            $requirePotluckDish = PotluckCategoryService::requiresPotluckDishCategoryFromRequest($data);
            $potluckAllowedCreate = PotluckCategoryService::parsePotluckAllowedSlugsFromEvent($eventPotluckRow);
            $potluckNorm = PotluckCategoryService::normalizePotluckSignup($data, $hasExtPotluckCols, $requirePotluckDish, $potluckAllowedCreate);
            if (!$potluckNorm['ok']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $potluckNorm['error']]);
                exit;
            }
        }
        if ($potluckNorm !== null && $potluckNorm['ok']
            && $potluckNorm['party_adults'] !== null && $potluckNorm['party_children'] !== null) {
            $guests = max(0, (int) $potluckNorm['party_adults'] + (int) $potluckNorm['party_children'] - 1);
        }

        $result = $rsvpService->createRSVP($eventId, $memberId, $guests, $familyMemberIds);

        if ($result['success']) {
            if (!empty($result['rsvp']['id'])) {
                headcount_mark_waiver_accepted($db, 'rsvps', (int) $result['rsvp']['id']);
            }
            if ($potluckNorm !== null && $potluckNorm['ok']) {
                try {
                    $applyEventIds = [$eventId];
                    $root = EventSeriesHelper::getSeriesRootId($db, $eventId);
                    $mode = EventSeriesHelper::getSessionRegistrationMode($db, $eventId);
                    if ($root && EventSeriesHelper::columnExists($db)) {
                        $seriesIds = EventSeriesHelper::getPublishedSeriesEventIds($db, (int) $root);
                        if (count($seriesIds) > 1 && $mode === EventSeriesHelper::MODE_ALL_SESSIONS) {
                            $applyEventIds = $seriesIds;
                        }
                    }
                    foreach ($applyEventIds as $tid) {
                        $r2 = $db->queryOne(
                            "SELECT id FROM rsvps WHERE event_id = :eid AND user_id = :uid AND status = 'yes'",
                            ['eid' => (int) $tid, 'uid' => $memberId]
                        );
                        $e2 = $db->queryOne('SELECT * FROM events WHERE id = ?', [(int) $tid]);
                        if ($r2 && $e2 && !empty($e2['is_potluck'])) {
                            $potluckApplyCreate = PotluckCategoryService::applyPayloadFromNormalization($potluckNorm);
                            PotluckCategoryService::applyPotluckState(
                                $db,
                                $e2,
                                (int) $r2['id'],
                                'yes',
                                $potluckApplyCreate,
                                $potluckApplyCreate !== null
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('Portal RSVP potluck apply: ' . $e->getMessage());
                }
            }
            // Save custom question answers (scalar or array stored as JSON)
            if (!empty($questionAnswers)) {
                try {
                    $rsvpId = (int)$result['rsvp']['id'];
                    foreach ($questionAnswers as $questionId => $answerText) {
                        $questionId = (int)$questionId;
                        if ($questionId <= 0) continue;
                        $stored = $normalizeAnswerForStorage($answerText);
                        if ($stored === null) continue;
                        $db->execute(
                            "INSERT INTO rsvp_question_answers (rsvp_id, question_id, answer_text) VALUES (:rsvp_id, :question_id, :answer_text)
                             ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text)",
                            ['rsvp_id' => $rsvpId, 'question_id' => $questionId, 'answer_text' => $stored]
                        );
                    }
                } catch (\Exception $e) {
                    error_log("Portal RSVP question_answers save error: " . $e->getMessage());
                }
            }
            // Send confirmation email
            try {
                $event = $db->queryOne(
                    "SELECT * FROM events WHERE id = :id",
                    ['id' => $eventId]
                );
                
                $member = $db->queryOne(
                    "SELECT * FROM users WHERE id = :id",
                    ['id' => $memberId]
                );

                if ($event && $member) {
                    $emailConfig = $getOrgEmailConfig($organizationId);
                    if ($emailConfig) {
                        $emailService = new PortalEmailService($emailConfig);
                        $emailService->sendRSVPConfirmation($result['rsvp'], $event, $member);
                    }
                }
            } catch (\Exception $e) {
                error_log("Failed to send RSVP confirmation email: " . $e->getMessage());
                // Don't fail RSVP if email fails
            }
        }

        echo json_encode($result);
        exit;
    }

    // PUT /api/portal/rsvps/{id} - Update RSVP
    if ($rsvpId && $method === 'PUT') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        // Verify RSVP belongs to member
        $rsvp = $db->queryOne(
            "SELECT * FROM rsvps WHERE id = :id AND user_id = :user_id",
            ['id' => $rsvpId, 'user_id' => $memberId]
        );

        if (!$rsvp) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'RSVP not found']);
            exit;
        }

        $eventId = (int)$rsvp['event_id'];
        $eventRow = $db->queryOne('SELECT * FROM events WHERE id = :id', ['id' => $eventId]);
        if (is_array($eventRow)) {
            $eventRow = EventSeriesHelper::mergeSeriesParentPolicyFields($db, $eventRow);
        }
        $finalStatus = isset($data['status']) ? trim((string) $data['status']) : (string) ($rsvp['status'] ?? 'yes');
        $fieldsProvided = array_key_exists('potluck_category', $data)
            || array_key_exists('potluck_item_note', $data)
            || array_key_exists('potluck_quantity', $data)
            || array_key_exists('potluck_serving_side', $data)
            || array_key_exists('potluck_party_adults', $data)
            || array_key_exists('potluck_party_children', $data)
            || array_key_exists('potluck_bringing_food', $data);
        $potluckErr = PotluckCategoryService::validateForYesPotluck(
            $db,
            is_array($eventRow) ? $eventRow : [],
            $finalStatus,
            $fieldsProvided,
            $data['potluck_category'] ?? null,
            $data['potluck_item_note'] ?? null,
            $rsvp,
            $data
        );
        if ($potluckErr !== null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $potluckErr]);
            exit;
        }

        $normPutCache = null;
        if ($fieldsProvided && is_array($eventRow) && !empty($eventRow['is_potluck']) && strtolower($finalStatus) === 'yes') {
            $hasExtPut = $db->hasColumn('rsvps', 'potluck_quantity')
                && $db->hasColumn('rsvps', 'potluck_serving_side')
                && $db->hasColumn('rsvps', 'potluck_party_adults')
                && $db->hasColumn('rsvps', 'potluck_party_children');
            $requirePutDish = PotluckCategoryService::requiresPotluckDishCategoryFromRequest($data);
            $potluckAllowedPut = is_array($eventRow) ? PotluckCategoryService::parsePotluckAllowedSlugsFromEvent($eventRow) : null;
            $normPutCache = PotluckCategoryService::normalizePotluckSignup($data, $hasExtPut, $requirePutDish, $potluckAllowedPut);
            if (!$normPutCache['ok']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $normPutCache['error']]);
                exit;
            }
            if ($normPutCache['party_adults'] !== null && $normPutCache['party_children'] !== null) {
                $data['guests'] = max(0, (int) $normPutCache['party_adults'] + (int) $normPutCache['party_children'] - 1);
            }
        }

        $questionAnswers = isset($data['question_answers']) && is_array($data['question_answers']) ? $data['question_answers'] : [];
        // Validate required questions (only when visible) if answers are being updated
        if (!empty($questionAnswers)) {
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
                // event_questions table may not exist or columns may not exist
            }
        }

        $updatePayload = $data;
        if (isset($data['family_member_ids']) && is_array($data['family_member_ids'])) {
            $fmIds = array_map('intval', $data['family_member_ids']);
            $placeholders = [];
            $params = ['user_id' => $memberId];
            foreach ($fmIds as $index => $fmId) {
                $key = 'fm_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $fmId;
            }
            if (!empty($fmIds)) {
                $placeholdersStr = implode(',', $placeholders);
                $validFamilyMembers = $db->query(
                    "SELECT id FROM family_members 
                     WHERE id IN ($placeholdersStr) AND parent_user_id = :user_id",
                    $params
                );
                if (count($validFamilyMembers) !== count(array_unique(array_filter($fmIds)))) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid family member(s) selected']);
                    exit;
                }
            }
            $updatePayload['family_member_ids'] = $fmIds;
        }

        $result = $rsvpService->updateRSVP($rsvpId, $updatePayload);

        if ($result['success']) {
            try {
                $potluckApplyPut = ($normPutCache && $normPutCache['ok'])
                    ? PotluckCategoryService::applyPayloadFromNormalization($normPutCache)
                    : null;
                PotluckCategoryService::applyPotluckState(
                    $db,
                    is_array($eventRow) ? $eventRow : [],
                    $rsvpId,
                    $finalStatus,
                    $potluckApplyPut,
                    (bool) ($normPutCache && $normPutCache['ok'] && $potluckApplyPut !== null)
                );
            } catch (\Throwable $e) {
                error_log('Portal RSVP update potluck: ' . $e->getMessage());
            }
        }

        // Save question_answers on update if provided (scalar or array as JSON)
        if ($result['success'] && !empty($questionAnswers)) {
            try {
                $db->execute("DELETE FROM rsvp_question_answers WHERE rsvp_id = :rsvp_id", ['rsvp_id' => $rsvpId]);
                foreach ($questionAnswers as $qId => $answerText) {
                    $qId = (int)$qId;
                    if ($qId <= 0) continue;
                    $stored = $normalizeAnswerForStorage($answerText);
                    if ($stored === null) continue;
                    $db->execute(
                        "INSERT INTO rsvp_question_answers (rsvp_id, question_id, answer_text) VALUES (:rsvp_id, :question_id, :answer_text)",
                        ['rsvp_id' => $rsvpId, 'question_id' => $qId, 'answer_text' => $stored]
                    );
                }
            } catch (\Exception $e) {
                error_log("Portal RSVP update question_answers error: " . $e->getMessage());
            }
        }

        echo json_encode($result);
        exit;
    }

    // DELETE /api/portal/rsvps/{id} - Cancel RSVP
    if ($rsvpId && $method === 'DELETE') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        // Verify RSVP belongs to member
        $rsvp = $db->queryOne(
            "SELECT * FROM rsvps WHERE id = :id AND user_id = :user_id",
            ['id' => $rsvpId, 'user_id' => $memberId]
        );

        if (!$rsvp) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'RSVP not found']);
            exit;
        }

        $result = $rsvpService->cancelRSVP($rsvpId);

        // Send cancellation email
        if ($result['success']) {
            try {
                $event = $db->queryOne(
                    "SELECT * FROM events WHERE id = :id",
                    ['id' => $rsvp['event_id']]
                );
                
                $member = $db->queryOne(
                    "SELECT * FROM users WHERE id = :id",
                    ['id' => $memberId]
                );

                if ($event && $member) {
                    $emailConfig = $getOrgEmailConfig($organizationId);
                    if ($emailConfig) {
                        $emailService = new PortalEmailService($emailConfig);
                        $emailService->sendRSVPCancellation($rsvp, $event, $member);
                    }
                }
            } catch (\Exception $e) {
                error_log("Failed to send RSVP cancellation email: " . $e->getMessage());
            }
        }

        echo json_encode($result);
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Portal RSVPs API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
