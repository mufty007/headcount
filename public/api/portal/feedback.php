<?php

/**
 * Portal Feedback API
 * Handles event feedback and ratings (members + guests via signed email link or email verification)
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
require_once HC_PROJECT_ROOT . '/src/helpers.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

/** Fixed rating question keys and labels */
function headcount_feedback_rating_questions(): array
{
    return [
        'overall' => 'Overall experience',
        'content' => 'Quality of content / program',
        'venue' => 'Venue & organization',
        'recommend' => 'Likelihood to recommend',
    ];
}

function headcount_feedback_normalize_event_row(array &$event): void
{
    if (isset($event['title']) && is_string($event['title'])) {
        $event['title'] = headcount_flatten_ampersand_in_plain_text($event['title']);
    }
}

function headcount_feedback_event_is_past(array $event): bool
{
    $today = date('Y-m-d');
    if (($event['event_date'] ?? '') < $today) {
        return true;
    }
    if (($event['event_date'] ?? '') === $today) {
        $endTime = $event['end_time'] ?? null;
        if ($endTime !== null && $endTime !== '' && $endTime < date('H:i:s')) {
            return true;
        }
    }
    return false;
}

function headcount_feedback_get_event($db, int $eventId): ?array
{
    $hasCollectCol = headcount_db_has_column($db, 'events', 'collect_feedback');
    $collectCol = $hasCollectCol ? ', e.collect_feedback' : ', 0 AS collect_feedback';
    $event = $db->queryOne(
        "SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.status{$collectCol}
         FROM events e
         WHERE e.id = :eid",
        ['eid' => $eventId]
    );
    if (!$event) {
        return null;
    }
    headcount_feedback_normalize_event_row($event);
    return $event;
}

function headcount_feedback_event_open($db, int $eventId): ?array
{
    $event = headcount_feedback_get_event($db, $eventId);
    if (!$event || empty($event['collect_feedback']) || ($event['status'] ?? '') !== 'published') {
        return null;
    }
    if (!headcount_feedback_event_is_past($event)) {
        return null;
    }
    return $event;
}

function headcount_feedback_event_eligible_for_user($db, int $eventId, int $userId): ?array
{
    $event = $db->queryOne(
        'SELECT e.* FROM events e
         JOIN attendance a ON a.event_id = e.id AND a.user_id = :uid
         WHERE e.id = :eid',
        ['eid' => $eventId, 'uid' => $userId]
    );
    if (!$event || empty($event['collect_feedback'])) {
        return null;
    }
    if (!headcount_feedback_event_is_past($event)) {
        return null;
    }
    headcount_feedback_normalize_event_row($event);
    return $event;
}

function headcount_feedback_resolve_user($db, int $eventId, array $data, array $config): ?array
{
    $uid = isset($data['uid']) ? (int) $data['uid'] : 0;
    $token = trim((string) ($data['token'] ?? ''));
    if ($uid > 0 && $token !== '' && headcount_event_feedback_verify_token($eventId, $uid, $token, $config)) {
        $user = $db->queryOne(
            'SELECT u.id, u.first_name, u.last_name, u.email
             FROM users u
             JOIN attendance a ON a.user_id = u.id AND a.event_id = :eid
             WHERE u.id = :uid',
            ['eid' => $eventId, 'uid' => $uid]
        );
        return $user ?: null;
    }

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '') {
        return null;
    }
    return $db->queryOne(
        'SELECT u.id, u.first_name, u.last_name, u.email
         FROM attendance a
         JOIN users u ON u.id = a.user_id
         WHERE a.event_id = :eid AND LOWER(u.email) = :email
         LIMIT 1',
        ['eid' => $eventId, 'email' => $email]
    ) ?: null;
}

function headcount_feedback_validate_scores($data): array
{
    $errors = [];
    $ratingScores = $data['rating_scores'] ?? null;
    if (is_string($ratingScores)) {
        $ratingScores = json_decode($ratingScores, true);
    }
    if (!is_array($ratingScores)) {
        $legacyRating = isset($data['rating']) ? (int) $data['rating'] : 0;
        if ($legacyRating >= 1 && $legacyRating <= 5) {
            $ratingScores = ['overall' => $legacyRating];
        } else {
            $ratingScores = [];
        }
    }

    foreach (array_keys(headcount_feedback_rating_questions()) as $key) {
        $val = isset($ratingScores[$key]) ? (int) $ratingScores[$key] : 0;
        if ($val < 1 || $val > 5) {
            $errors[] = 'All rating questions must be answered (1–5 stars)';
            break;
        }
        $ratingScores[$key] = $val;
    }

    return ['errors' => $errors, 'rating_scores' => $ratingScores];
}

function headcount_feedback_save($db, int $eventId, int $userId, array $ratingScores, string $feedbackText, string $submittedVia): void
{
    $overall = (int) ($ratingScores['overall'] ?? 0);
    $payload = [
        'rating' => $overall,
        'feedback_text' => $feedbackText !== '' ? $feedbackText : null,
    ];
    if (headcount_db_has_column($db, 'event_feedback', 'rating_scores')) {
        $payload['rating_scores'] = json_encode($ratingScores);
    }
    if (headcount_db_has_column($db, 'event_feedback', 'submitted_via')) {
        $payload['submitted_via'] = $submittedVia === 'email_link' ? 'email_link' : 'portal';
    }

    $existing = $db->queryOne(
        'SELECT id FROM event_feedback WHERE event_id = :event_id AND user_id = :user_id',
        ['event_id' => $eventId, 'user_id' => $userId]
    );

    if ($existing) {
        $db->update('event_feedback', $existing['id'], $payload);
    } else {
        $payload['event_id'] = $eventId;
        $payload['user_id'] = $userId;
        $db->insert('event_feedback', $payload);
    }
}

// Load config
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

$pathSegments = explode('/', trim($path, '/'));
$action = $pathSegments[count($pathSegments) - 1] ?? '';
$eventId = null;
if (is_numeric($action) && count($pathSegments) >= 2 && $pathSegments[count($pathSegments) - 2] === 'event') {
    $eventId = (int) $action;
    $action = 'event';
}

if (!isset($input)) {
    $input = json_decode(@file_get_contents('php://input'), true) ?? [];
}
if (!isset($data)) {
    $data = array_merge($_POST, $_GET, $input);
}

$db = Database::getInstance();

try {
    // GET /api/portal/feedback/event-info?event_id=X — public event summary for feedback form
    if ($action === 'event-info' && $method === 'GET') {
        $eid = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        if ($eid <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'event_id is required']);
            exit;
        }

        $event = headcount_feedback_event_open($db, $eid);
        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Feedback is not available for this event']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'event' => [
                'id' => (int) $event['id'],
                'title' => $event['title'],
                'event_date' => $event['event_date'],
                'start_time' => $event['start_time'] ?? null,
            ],
            'questions' => headcount_feedback_rating_questions(),
        ]);
        exit;
    }

    // GET /api/portal/feedback/eligible - Past attended events open for feedback (members)
    if ($action === 'eligible' && $method === 'GET') {
        PortalAuthMiddleware::requireAuth();
        $memberId = PortalAuthMiddleware::getMemberId();

        $hasCollectCol = headcount_db_has_column($db, 'events', 'collect_feedback');
        $collectFilter = $hasCollectCol ? 'AND e.collect_feedback = 1' : 'AND 1=0';

        $events = $db->query(
            "SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, a.checked_in_at,
                    (SELECT COUNT(*) FROM event_feedback ef WHERE ef.event_id = e.id AND ef.user_id = :uid2) AS has_feedback
             FROM events e
             JOIN attendance a ON e.id = a.event_id
             WHERE a.user_id = :uid
             {$collectFilter}
             AND (e.event_date < CURDATE() OR (e.event_date = CURDATE() AND (e.end_time IS NOT NULL AND e.end_time < CURTIME())))
             ORDER BY a.checked_in_at DESC",
            ['uid' => $memberId, 'uid2' => $memberId]
        );

        foreach ($events as &$ev) {
            headcount_feedback_normalize_event_row($ev);
        }
        unset($ev);

        echo json_encode([
            'success' => true,
            'events' => $events,
            'questions' => headcount_feedback_rating_questions(),
        ]);
        exit;
    }

    // GET /api/portal/feedback/mine?event_id=X - Current member's feedback for an event
    if ($action === 'mine' && $method === 'GET') {
        $eid = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        if ($eid <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'event_id is required']);
            exit;
        }

        $memberId = PortalAuthMiddleware::isAuthenticated() ? PortalAuthMiddleware::getMemberId() : 0;
        $guestUid = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;
        $guestToken = trim((string) ($_GET['token'] ?? ''));

        if ($memberId <= 0 && $guestUid > 0 && $guestToken !== '') {
            $user = headcount_feedback_resolve_user($db, $eid, ['uid' => $guestUid, 'token' => $guestToken], $config);
            $memberId = $user ? (int) $user['id'] : 0;
        }

        if ($memberId <= 0) {
            PortalAuthMiddleware::requireAuth();
            $memberId = PortalAuthMiddleware::getMemberId();
        }

        $row = $db->queryOne(
            'SELECT * FROM event_feedback WHERE event_id = :eid AND user_id = :uid',
            ['eid' => $eid, 'uid' => $memberId]
        );
        if ($row && !empty($row['rating_scores']) && is_string($row['rating_scores'])) {
            $row['rating_scores'] = json_decode($row['rating_scores'], true);
        }

        echo json_encode([
            'success' => true,
            'feedback' => $row ?: null,
            'questions' => headcount_feedback_rating_questions(),
        ]);
        exit;
    }

    // POST /api/portal/feedback/guest - Guest submit (signed link or email verification)
    if ($action === 'guest' && $method === 'POST') {
        CsrfMiddleware::verify($data);

        $errors = [];
        $eventId = isset($data['event_id']) ? (int) $data['event_id'] : 0;
        $feedbackText = trim((string) ($data['feedback_text'] ?? ''));
        $submittedVia = ($data['submitted_via'] ?? '') === 'email_link' ? 'email_link' : 'portal';

        if ($eventId <= 0) {
            $errors[] = 'Event ID is required';
        }

        $scoreResult = headcount_feedback_validate_scores($data);
        $errors = array_merge($errors, $scoreResult['errors']);
        $ratingScores = $scoreResult['rating_scores'];

        $user = null;
        if (empty($errors)) {
            $user = headcount_feedback_resolve_user($db, $eventId, $data, $config);
            if (!$user) {
                $errors[] = 'We could not verify your attendance. Use the email address you checked in with, or open the link from your feedback email.';
            }
        }

        if (empty($errors)) {
            $event = headcount_feedback_event_eligible_for_user($db, $eventId, (int) $user['id']);
            if (!$event) {
                $errors[] = 'Feedback is not available for this event';
            }
        }

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        headcount_feedback_save($db, $eventId, (int) $user['id'], $ratingScores, $feedbackText, $submittedVia);

        echo json_encode([
            'success' => true,
            'message' => 'Feedback submitted successfully',
        ]);
        exit;
    }

    // POST /api/portal/feedback - Submit feedback (members)
    if (($action === 'feedback' || $action === '') && $method === 'POST') {
        CsrfMiddleware::verify($data);
        PortalAuthMiddleware::requireAuth();
        $memberId = PortalAuthMiddleware::getMemberId();

        $errors = [];
        $eventId = isset($data['event_id']) ? (int) $data['event_id'] : 0;
        $feedbackText = trim((string) ($data['feedback_text'] ?? ''));
        $submittedVia = ($data['submitted_via'] ?? 'portal') === 'email_link' ? 'email_link' : 'portal';

        if ($eventId <= 0) {
            $errors[] = 'Event ID is required';
        }

        $scoreResult = headcount_feedback_validate_scores($data);
        $errors = array_merge($errors, $scoreResult['errors']);
        $ratingScores = $scoreResult['rating_scores'];

        if (empty($errors)) {
            $event = headcount_feedback_event_eligible_for_user($db, $eventId, $memberId);
            if (!$event) {
                $errors[] = 'Feedback is not available for this event';
            }
        }

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        headcount_feedback_save($db, $eventId, $memberId, $ratingScores, $feedbackText, $submittedVia);

        echo json_encode([
            'success' => true,
            'message' => 'Feedback submitted successfully',
        ]);
        exit;
    }

    // GET /api/portal/feedback/event/{id} - Aggregate feedback (legacy)
    if ($action === 'event' && $eventId && $method === 'GET') {
        $feedback = $db->query(
            'SELECT f.*, u.first_name, u.last_name
             FROM event_feedback f
             JOIN users u ON f.user_id = u.id
             WHERE f.event_id = :event_id
             ORDER BY f.created_at DESC',
            ['event_id' => $eventId]
        );

        $avgRating = $db->queryOne(
            'SELECT AVG(rating) as avg_rating, COUNT(*) as count
             FROM event_feedback
             WHERE event_id = :event_id',
            ['event_id' => $eventId]
        );

        echo json_encode([
            'success' => true,
            'feedback' => $feedback,
            'average_rating' => round($avgRating['avg_rating'] ?? 0, 2),
            'rating_count' => (int) ($avgRating['count'] ?? 0),
        ]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
} catch (\Exception $e) {
    http_response_code(500);
    error_log('Portal feedback API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage(),
    ]);
}
