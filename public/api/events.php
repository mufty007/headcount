<?php
// Start output buffering to prevent any accidental output
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Disable error display completely, we'll handle errors ourselves
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set error handler to catch any errors and prevent output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $msg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($msg);
    if (!(error_reporting() & $errno)) return false;
    
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Internal Server Error', 'error' => $msg]);
    exit;
}, E_ALL);

// Set exception handler
set_exception_handler(function($exception) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

// Set shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'message' => 'Fatal Server Error: ' . $error['message'],
            'error' => $error
        ]);
        error_log("Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    }
});

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Auth;
use Headcount\Helpers\NotificationHelper;
use Headcount\Helpers\Validator;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Core\FileUpload;
use Headcount\Services\EventEligibilityService;
use Headcount\Services\EventSeriesHelper;
use Headcount\Services\EventHeadcountPricingService;
use Headcount\Services\EventQuestionMergeService;
use Headcount\Services\PotluckCategoryService;
use Headcount\Services\EventPeopleService;
use Headcount\Services\EventVisibilityService;
use Headcount\Services\EventInviteService;
use Headcount\Services\EventCalendarService;
use Headcount\Services\PortalEmailService;
use Headcount\Helpers\Security;
use Headcount\Helpers\EventTicketTypesPersistence;

try {
    
    // Load RecurringEventService if it exists (optional dependency)
    $recurringServiceClass = null;
    $recurringServicePath = __DIR__ . '/../../src/Services/RecurringEventService.php';
    if (file_exists($recurringServicePath)) {
        require_once $recurringServicePath;
        $recurringServiceClass = 'Headcount\Services\RecurringEventService';
    }

    // Load config
    $config = require HC_PROJECT_ROOT . '/config/config.php';

    // Initialize database
    Database::getInstance($config['database']);

    // Start session if needed (suppress warnings)
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Clear any output that may have been generated
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);

    // Check authentication: coordinators may only read events (list/get) for check-in flow
    AuthMiddleware::check();
    $db = Database::getInstance();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    // When `action` is omitted, do not assume `get` (that requires `id`). Bare GET with no `id` query key returns the same list as action=list.
    $action = isset($_GET['action']) ? (string) $_GET['action'] : null;
    if ($action === null || $action === '') {
        if ($method === 'GET' && !array_key_exists('id', $_GET)) {
            $action = 'list';
        } else {
            $action = 'get';
        }
    }
    $isReadOnly = ($action === 'list' && $method === 'GET')
        || ($action === 'get' && $method === 'GET')
        || ($action === 'calendar' && $method === 'GET')
        || ($action === 'event-invites' && $method === 'GET');
    if ($isReadOnly) {
        AuthMiddleware::requireAdminOrCoordinator();
    } else {
        AuthMiddleware::requireAdmin();
        if ($action === 'create' || $action === 'duplicate') {
            AuthMiddleware::requireCan('events.manage');
        }
    }
    $organizationId = AuthMiddleware::getOrganizationId();
    $userId = AuthMiddleware::getUserId();

    // GET list of events (for dropdowns, e.g. email compose)
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $events = $db->query(
            "SELECT id, title, event_date, start_time, location FROM events 
             WHERE organization_id = :org_id AND status != 'cancelled' 
             ORDER BY event_date DESC, start_time DESC",
            ['org_id' => $organizationId]
        );
        jsonResponse(['success' => true, 'events' => $events]);
        exit;
    }

    if ($action === 'calendar' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $start = trim((string) ($_GET['start'] ?? date('Y-m-d')));
        $end = trim((string) ($_GET['end'] ?? date('Y-m-d', strtotime('+30 days'))));
        $filters = [];
        if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
            $filters['status'] = (string) $_GET['status'];
        }
        if (!empty($_GET['category_id'])) {
            $filters['category_id'] = (int) $_GET['category_id'];
        }
        $calSvc = new EventCalendarService();
        $events = $calSvc->getCalendarEvents($organizationId, $start, $end, $filters);
        jsonResponse(['success' => true, 'events' => $events]);
        exit;
    }

    // GET single event (for edit: if instance, load parent so recurrence shows correctly)
    if ($action === 'get') {
        $eventId = Validator::getParam('id', 'id', null);
        if (!$eventId) {
            jsonResponse(['success' => false, 'message' => 'Invalid event ID'], 400);
            exit;
        }
        $event = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND organization_id = :org_id",
            ['id' => $eventId, 'org_id' => $organizationId]
        );
        
        if ($event) {
            // When editing a recurring instance, load the parent event so the form shows series recurrence
            $parentId = isset($event['parent_event_id']) ? (int)$event['parent_event_id'] : 0;
            if ($parentId && (!empty($event['is_recurring_instance']) || $event['parent_event_id'])) {
                $parent = $db->queryOne(
                    "SELECT * FROM events WHERE id = :id AND organization_id = :org_id",
                    ['id' => $parentId, 'org_id' => $organizationId]
                );
                if ($parent) {
                    $event = $parent;
                    $eventId = $parent['id'];
                }
            }
            
            // Get event categories
            try {
                $eventCategories = $db->query(
                    "SELECT category_id FROM event_categories WHERE event_id = :event_id",
                    ['event_id' => $eventId]
                );
                $event['categories'] = array_column($eventCategories, 'category_id');
            } catch (Exception $e) {
                $event['categories'] = [];
            }
            
            // Get recurring event data if this is a parent event
            if (!isset($event['is_recurring_instance']) || !$event['is_recurring_instance']) {
                try {
                    $recurring = $db->queryOne(
                        "SELECT * FROM recurring_events WHERE parent_event_id = :event_id",
                        ['event_id' => $eventId]
                    );
                    if ($recurring) {
                        $event['is_recurring'] = true;
                        $event['recurrence_type'] = $recurring['recurrence_type'];
                        $event['recurrence_interval'] = $recurring['interval'];
                        $event['recurrence_days'] = $recurring['days_of_week'] ? array_map('intval', array_map('trim', explode(',', $recurring['days_of_week']))) : [];
                        $event['recurrence_week_of_month'] = isset($recurring['week_of_month']) ? (int)$recurring['week_of_month'] : null;
                        $event['recurrence_end_type'] = $recurring['end_type'];
                        $event['recurrence_end_after_count'] = $recurring['end_after_count'];
                        $event['recurrence_end_date'] = $recurring['end_date'];
                        $event['custom_session_dates'] = [];
                        if (!empty($recurring['custom_dates'])) {
                            $decodedCustom = json_decode($recurring['custom_dates'], true);
                            if (is_array($decodedCustom)) {
                                $event['custom_session_dates'] = $decodedCustom;
                            }
                        }
                    } else {
                        $event['is_recurring'] = false;
                    }
                } catch (Exception $e) {
                    $event['is_recurring'] = false;
                }
            } else {
                $event['is_recurring'] = false;
            }
            
            // Get event custom questions (with options and conditional fields)
            try {
                $hasDependsOnColumns = $db->hasColumn('event_questions', 'depends_on_question_id');
                $questionsSql = $hasDependsOnColumns
                    ? "SELECT id, question_text, question_type, is_required, sort_order, depends_on_question_id, depends_on_value FROM event_questions WHERE event_id = :event_id ORDER BY sort_order ASC, id ASC"
                    : "SELECT id, question_text, question_type, is_required, sort_order FROM event_questions WHERE event_id = :event_id ORDER BY sort_order ASC, id ASC";
                $event['questions'] = $db->query($questionsSql, ['event_id' => $eventId]);
                $qIds = array_column($event['questions'], 'id');
                $optionsByQ = [];
                if (!empty($qIds)) {
                    try {
                        $placeholders = implode(',', array_fill(0, count($qIds), '?'));
                        $opts = $db->query(
                            "SELECT id, question_id, option_label, sort_order 
                             FROM event_question_options 
                             WHERE question_id IN ($placeholders) 
                             ORDER BY question_id ASC, sort_order ASC, id ASC",
                            $qIds
                        );
                        foreach ($opts as $o) {
                            $optionsByQ[$o['question_id']][] = [
                                'id' => (int)$o['id'],
                                'option_label' => $o['option_label'],
                                'sort_order' => (int)$o['sort_order']
                            ];
                        }
                    } catch (\Exception $e) {
                        // event_question_options table may not exist yet
                    }
                }
                foreach ($event['questions'] as &$q) {
                    $q['options'] = $optionsByQ[$q['id']] ?? [];
                    if ($hasDependsOnColumns) {
                        $q['depends_on_question_id'] = isset($q['depends_on_question_id']) && $q['depends_on_question_id'] !== null ? (int)$q['depends_on_question_id'] : null;
                        $q['depends_on_value'] = isset($q['depends_on_value']) && $q['depends_on_value'] !== null && trim((string)$q['depends_on_value']) !== ''
                            ? trim((string)$q['depends_on_value'])
                            : null;
                    } else {
                        $q['depends_on_question_id'] = null;
                        $q['depends_on_value'] = null;
                    }
                }
                unset($q);
            } catch (\Exception $e) {
                $event['questions'] = [];
            }

            // Load event ticket types
            try {
                $ttExtra = $db->hasColumn('event_ticket_types', 'sale_starts_at')
                    ? ', sale_starts_at, sale_ends_at, package_group'
                    : '';
                $event['ticket_types'] = $db->query(
                    "SELECT id, event_id, name, price, quantity_limit, sort_order{$ttExtra}
                     FROM event_ticket_types 
                     WHERE event_id = :event_id 
                     ORDER BY sort_order ASC, id ASC",
                    ['event_id' => $eventId]
                );
            } catch (\Exception $e) {
                $event['ticket_types'] = [];
            }

            $eventPeopleSvc = new EventPeopleService();
            if ($eventPeopleSvc->tableExists()) {
                $event['event_people'] = $eventPeopleSvc->listForEventId((int) $eventId);
            } else {
                $event['event_people'] = [];
            }
            
            jsonResponse(['success' => true, 'event' => $event]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
        }
    }

    // GET RSVPs for an event (admin only) with summary stats
    if ($action === 'rsvps' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $eventId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0);
        if (!$eventId) {
            jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
            exit;
        }
        $event = $db->queryOne(
            "SELECT id, event_date, parent_event_id, capacity FROM events WHERE id = :id AND organization_id = :org_id",
            ['id' => $eventId, 'org_id' => $organizationId]
        );
        if (!$event) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            exit;
        }
        $rsvpSourceEventId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
        $parentForAttendance = !empty($event['parent_event_id']) ? (int) $event['parent_event_id'] : 0;
        $eventDateYmd = substr((string) ($event['event_date'] ?? ''), 0, 10);
        $summary = [
            'counts' => ['yes' => 0, 'no' => 0, 'maybe' => 0, 'total_rsvps' => 0],
            'attendance' => ['checked_in_yes' => 0, 'not_checked_in_yes' => 0, 'expected_head_count' => 0, 'total_at_door_heads' => 0, 'walk_in_heads' => 0],
            'capacity' => null,
            'available_spots' => null,
            'no_response_count' => 0,
        ];

        try {
            $rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
            $rsvpColNames = array_column($rsvpCols, 'Field');
            $guestCountCol = in_array('guest_count', $rsvpColNames) ? ', r.guest_count' : '';
            $potluckCols = '';
            if (in_array('potluck_category', $rsvpColNames, true)) {
                $potluckCols .= ', r.potluck_category';
            }
            if (in_array('potluck_item_note', $rsvpColNames, true)) {
                $potluckCols .= ', r.potluck_item_note';
            }
            if (in_array('potluck_quantity', $rsvpColNames, true)) {
                $potluckCols .= ', r.potluck_quantity';
            }
            if (in_array('potluck_serving_side', $rsvpColNames, true)) {
                $potluckCols .= ', r.potluck_serving_side';
            }
            if (in_array('potluck_party_adults', $rsvpColNames, true)) {
                $potluckCols .= ', r.potluck_party_adults';
            }
            if (in_array('potluck_party_children', $rsvpColNames, true)) {
                $potluckCols .= ', r.potluck_party_children';
            }
            $ticketSelectionCol = in_array('ticket_selection_json', $rsvpColNames, true)
                ? ', r.ticket_selection_json'
                : '';
            $rows = $db->query(
                "SELECT r.id, r.user_id, r.status, r.created_at, r.notes{$guestCountCol}{$potluckCols}{$ticketSelectionCol},
                        u.first_name, u.last_name, u.email, u.phone, u.password_hash
                 FROM rsvps r
                 JOIN users u ON r.user_id = u.id
                 WHERE r.event_id = :event_id
                 ORDER BY r.created_at DESC",
                ['event_id' => $rsvpSourceEventId]
            );
            $paymentCols = $db->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
            $hasPaymentMethod = !empty($paymentCols);
            $byUser = [];
            if (!empty($rows)) {
                $userIds = array_unique(array_column($rows, 'user_id'));
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $pmSelect = $hasPaymentMethod ? 'p.payment_method' : "'stripe' AS payment_method";
                $payments = $db->query(
                    "SELECT p.user_id, p.id as payment_id, p.amount as payment_amount, {$pmSelect}, p.status as payment_status, p.refund_amount
                     FROM payments p
                     WHERE p.event_id = ? AND p.user_id IN ($placeholders)
                     ORDER BY p.user_id, CASE p.status WHEN 'paid' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, p.id DESC",
                    array_merge([$rsvpSourceEventId], $userIds)
                );
                foreach ($payments as $p) {
                    $uid = (int) $p['user_id'];
                    if (isset($byUser[$uid])) {
                        continue;
                    }
                    $amt = (float) $p['payment_amount'];
                    $refundAmt = (float) ($p['refund_amount'] ?? 0);
                    $status = $p['payment_status'] ?? 'paid';
                    $refunded = ($status === 'refunded') || ($refundAmt >= $amt);
                    $byUser[$uid] = [
                        'payment_id' => (int) $p['payment_id'],
                        'payment_amount' => $amt,
                        'payment_method' => $p['payment_method'] ?? 'stripe',
                        'payment_status' => $status,
                        'is_refunded' => $refunded,
                    ];
                }
            }
            // Load question answers for all RSVPs (if rsvp_question_answers exists)
            $questionAnswersByRsvp = [];
            try {
                $rsvpIds = array_column($rows, 'id');
                if (!empty($rsvpIds)) {
                    $placeholders = implode(',', array_fill(0, count($rsvpIds), '?'));
                    $answersRows = $db->query(
                        "SELECT rqa.rsvp_id,
                                rqa.question_id,
                                COALESCE(eq.question_text, CONCAT('Question #', rqa.question_id)) AS question_text,
                                COALESCE(eq.sort_order, 999999) AS question_sort_order,
                                rqa.answer_text
                         FROM rsvp_question_answers rqa
                         LEFT JOIN event_questions eq ON eq.id = rqa.question_id
                         WHERE rqa.rsvp_id IN ($placeholders)
                         ORDER BY question_sort_order ASC, rqa.question_id ASC",
                        $rsvpIds
                    );
                    foreach ($answersRows as $ar) {
                        $rid = (int)$ar['rsvp_id'];
                        if (!isset($questionAnswersByRsvp[$rid])) {
                            $questionAnswersByRsvp[$rid] = [];
                        }
                        $questionAnswersByRsvp[$rid][] = [
                            'question_id' => (int)$ar['question_id'],
                            'question_text' => $ar['question_text'],
                            'question_sort_order' => isset($ar['question_sort_order']) ? (int)$ar['question_sort_order'] : 0,
                            'answer_text' => $ar['answer_text'],
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Table may not exist
            }

            $attendanceByUser = [];
            if (!empty($rows)) {
                try {
                    $userIdsForAtt = array_values(array_unique(array_map('intval', array_column($rows, 'user_id'))));
                    $hasFmAttCol = $db->hasColumn('attendance', 'family_member_id');
                    $phAtt = implode(',', array_fill(0, count($userIdsForAtt), '?'));
                    $attSql = "SELECT a.user_id, a.checked_in_at
                         FROM attendance a
                         WHERE a.checked_in_at IS NOT NULL
                         AND DATE(a.checked_in_at) = ?
                         AND a.event_id IN (?, ?)
                         AND a.user_id IN ($phAtt)";
                    if ($hasFmAttCol) {
                        $attSql .= ' AND IFNULL(a.family_member_id, 0) = 0';
                    }
                    $attParams = array_merge([$eventDateYmd, $eventId, $parentForAttendance], $userIdsForAtt);
                    $attRows = $db->query($attSql, $attParams);
                    foreach ($attRows as $attRow) {
                        $uid = (int) $attRow['user_id'];
                        if (!isset($attendanceByUser[$uid])) {
                            $attendanceByUser[$uid] = $attRow['checked_in_at'];
                        }
                    }
                } catch (\Exception $e) {
                    error_log('RSVP attendance enrichment: ' . $e->getMessage());
                }
            }

            $eventQuestions = [];
            $potluckFallbackQuestionId = null;
            try {
                $questionEventIds = array_values(array_unique(array_filter([$eventId, $rsvpSourceEventId], static fn ($id) => (int) $id > 0)));
                $qEventPh = implode(',', array_map('intval', $questionEventIds));
                $eventQuestions = $db->query(
                    "SELECT id, question_text, question_type, sort_order, depends_on_question_id, depends_on_value
                     FROM event_questions
                     WHERE event_id IN ($qEventPh)
                     ORDER BY sort_order ASC, id ASC"
                );
                if (!is_array($eventQuestions)) {
                    $eventQuestions = [];
                }
                foreach ($eventQuestions as &$questionRow) {
                    $questionRow['id'] = isset($questionRow['id']) ? (int) $questionRow['id'] : 0;
                    $questionRow['question_text'] = isset($questionRow['question_text']) ? (string) $questionRow['question_text'] : '';
                    $questionRow['question_type'] = isset($questionRow['question_type']) ? (string) $questionRow['question_type'] : 'short_text';
                    $questionRow['sort_order'] = isset($questionRow['sort_order']) ? (int) $questionRow['sort_order'] : 0;
                    $questionRow['depends_on_question_id'] = isset($questionRow['depends_on_question_id']) ? (int) $questionRow['depends_on_question_id'] : null;
                    $questionRow['depends_on_value'] = isset($questionRow['depends_on_value']) ? trim((string) $questionRow['depends_on_value']) : null;
                }
                unset($questionRow);

                foreach ($eventQuestions as $questionRow) {
                    if (!in_array($questionRow['question_type'], ['short_text', 'text'], true)) {
                        continue;
                    }
                    $text = strtolower($questionRow['question_text']);
                    $isPotluckLike = (strpos($text, 'bring') !== false) || (strpos($text, 'potluck') !== false) || (strpos($text, 'food') !== false);
                    if ($questionRow['depends_on_question_id'] && $isPotluckLike) {
                        $potluckFallbackQuestionId = (int) $questionRow['id'];
                        break;
                    }
                }
                if ($potluckFallbackQuestionId === null) {
                    foreach ($eventQuestions as $questionRow) {
                        if (!in_array($questionRow['question_type'], ['short_text', 'text'], true)) {
                            continue;
                        }
                        $text = strtolower($questionRow['question_text']);
                        $isPotluckLike = (strpos($text, 'bring') !== false) || (strpos($text, 'potluck') !== false) || (strpos($text, 'food') !== false);
                        if ($isPotluckLike) {
                            $potluckFallbackQuestionId = (int) $questionRow['id'];
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                $eventQuestions = [];
                $potluckFallbackQuestionId = null;
            }

            $rsvps = [];
            $totalHeadCount = 0;
            foreach ($rows as $r) {
                $rsvp = $r;
                $rsvp['user_type'] = (!empty($r['password_hash'])) ? 'Member' : 'Guest';
                unset($rsvp['password_hash']);
                if (isset($byUser[(int) $r['user_id']])) {
                    $pu = $byUser[(int) $r['user_id']];
                    $rsvp['payment_id'] = $pu['payment_id'];
                    $rsvp['payment_amount'] = $pu['payment_amount'];
                    $rsvp['payment_method'] = $pu['payment_method'];
                    $rsvp['payment_status'] = $pu['payment_status'] ?? 'paid';
                    $rsvp['is_refunded'] = !empty($pu['is_refunded']);
                } else {
                    $rsvp['payment_id'] = null;
                    $rsvp['payment_amount'] = null;
                    $rsvp['payment_method'] = null;
                    $rsvp['payment_status'] = null;
                    $rsvp['is_refunded'] = false;
                }
                $rsvp['question_answers'] = $questionAnswersByRsvp[(int)$r['id']] ?? [];
                $primaryUid = (int) ($r['user_id'] ?? 0);
                $checkedInAt = $attendanceByUser[$primaryUid] ?? null;
                $rsvp['checked_in'] = $checkedInAt !== null && $checkedInAt !== '';
                $rsvp['checked_in_at'] = $checkedInAt;
                $pc = isset($r['potluck_category']) ? trim((string) $r['potluck_category']) : '';
                $rsvp['potluck_category'] = $pc !== '' ? $pc : null;
                $rsvp['potluck_item_note'] = isset($r['potluck_item_note']) ? (string) $r['potluck_item_note'] : null;
                $rsvp['potluck_category_label'] = $pc !== '' ? (PotluckCategoryService::labelForSlug($pc) ?? $pc) : null;
                if (in_array('potluck_quantity', $rsvpColNames, true)) {
                    $rsvp['potluck_quantity'] = isset($r['potluck_quantity']) ? (int) $r['potluck_quantity'] : null;
                }
                if (in_array('potluck_serving_side', $rsvpColNames, true)) {
                    $side = isset($r['potluck_serving_side']) ? trim((string) $r['potluck_serving_side']) : '';
                    $rsvp['potluck_serving_side'] = $side !== '' ? $side : null;
                    $rsvp['potluck_serving_side_label'] = $side !== '' ? (PotluckCategoryService::labelForServingSide($side) ?? $side) : null;
                }
                if (in_array('potluck_party_adults', $rsvpColNames, true)) {
                    $rsvp['potluck_party_adults'] = isset($r['potluck_party_adults']) ? (int) $r['potluck_party_adults'] : null;
                }
                if (in_array('potluck_party_children', $rsvpColNames, true)) {
                    $rsvp['potluck_party_children'] = isset($r['potluck_party_children']) ? (int) $r['potluck_party_children'] : null;
                }
                if (in_array('ticket_selection_json', $rsvpColNames, true)) {
                    $rsvp['ticket_selection_summary'] = \Headcount\Services\EventTicketSelectionService::formatSelectionSummary(
                        $r['ticket_selection_json'] ?? null
                    );
                }
                if ($potluckFallbackQuestionId !== null) {
                    $note = trim((string) ($rsvp['potluck_item_note'] ?? ''));
                    if ($note !== '') {
                        $hasMappedAnswer = false;
                        foreach ($rsvp['question_answers'] as $qaRow) {
                            if ((int) ($qaRow['question_id'] ?? 0) === $potluckFallbackQuestionId) {
                                $hasMappedAnswer = true;
                                break;
                            }
                        }
                        if (!$hasMappedAnswer) {
                            $targetQuestion = null;
                            foreach ($eventQuestions as $questionRow) {
                                if ((int) ($questionRow['id'] ?? 0) === $potluckFallbackQuestionId) {
                                    $targetQuestion = $questionRow;
                                    break;
                                }
                            }
                            if ($targetQuestion) {
                                $rsvp['question_answers'][] = [
                                    'question_id' => (int) $targetQuestion['id'],
                                    'question_text' => (string) ($targetQuestion['question_text'] ?? ''),
                                    'question_sort_order' => isset($targetQuestion['sort_order']) ? (int) $targetQuestion['sort_order'] : 0,
                                    'answer_text' => $note,
                                ];
                            }
                        }
                    }
                }
                $rsvps[] = $rsvp;
                // Aggregate counts
                $status = strtolower($rsvp['status'] ?? '');
                if (isset($summary['counts'][$status])) {
                    $summary['counts'][$status]++;
                }
                $summary['counts']['total_rsvps']++;
                // Head count for capacity: each yes RSVP = 1 + guests
                if ($status === 'yes') {
                    $totalHeadCount += 1 + headcount_rsvp_guests_for_checkin($r);
                }
            }
            $summary['counts']['total_head_count'] = $totalHeadCount;
            $yesCount = (int)($summary['counts']['yes'] ?? 0);
            $summary['counts']['total_guests'] = max(0, $totalHeadCount - $yesCount);

            // Attendance: head counts scoped to this session date (RSVP yes vs walk-ins vs total at door)
            try {
                $attSummary = headcount_event_session_attendance_summary(
                    $db,
                    $eventId,
                    $rsvpSourceEventId,
                    $parentForAttendance,
                    $eventDateYmd
                );
                $expectedHeads = (int) ($summary['counts']['total_head_count'] ?? 0);
                $rsvpCheckedHeads = (int) ($attSummary['rsvp_yes_checked_in_heads'] ?? 0);
                $summary['attendance']['checked_in_yes'] = $rsvpCheckedHeads;
                $summary['attendance']['not_checked_in_yes'] = max(0, $expectedHeads - $rsvpCheckedHeads);
                $summary['attendance']['expected_head_count'] = $expectedHeads;
                $summary['attendance']['total_at_door_heads'] = (int) ($attSummary['total_at_door_heads'] ?? 0);
                $summary['attendance']['walk_in_heads'] = (int) ($attSummary['walk_in_heads'] ?? 0);
            } catch (\Exception $e) {
                // Attendance table might not exist; leave defaults
            }
            if (!isset($summary['attendance']['expected_head_count'])) {
                $summary['attendance']['expected_head_count'] = (int) ($summary['counts']['total_head_count'] ?? 0);
            }

            // Capacity and available spots (if events.capacity exists) — use head count (people + guests)
            try {
                $eventRow = $db->queryOne(
                    "SELECT capacity FROM events WHERE id = :id",
                    ['id' => $eventId]
                );
                if ($eventRow && !empty($eventRow['capacity'])) {
                    $capacity = (int)$eventRow['capacity'];
                    $summary['capacity'] = $capacity;
                    $summary['available_spots'] = max(0, $capacity - ($summary['counts']['total_head_count'] ?? 0));
                }
            } catch (\Exception $e) {
                // ignore
            }

            // No-response count: active members with no RSVP for this event
            try {
                $noRespRow = $db->queryOne(
                    "SELECT COUNT(*) AS c
                     FROM users u
                     WHERE u.organization_id = :org_id
                       AND u.role = 'member'
                       AND u.status = 'active'
                       AND NOT EXISTS (
                           SELECT 1 FROM rsvps r2
                           WHERE r2.event_id = :event_id
                             AND r2.user_id = u.id
                       )",
                    ['org_id' => $organizationId, 'event_id' => $rsvpSourceEventId]
                );
                $summary['no_response_count'] = (int)($noRespRow['c'] ?? 0);
            } catch (\Exception $e) {
                // ignore
            }
        } catch (\Exception $e) {
            error_log("RSVPs query error: " . $e->getMessage());
            $rsvps = [];
        }

        if (!empty($rsvps)) {
            try {
                $eligSvc = new EventEligibilityService($db);
                if ($eligSvc->rsvpFamilyMembersTableExists()) {
                    $rsvpIds = array_map('intval', array_column($rsvps, 'id'));
                    $rsvpIds = array_values(array_filter($rsvpIds));
                    if (!empty($rsvpIds)) {
                        $ph = implode(',', array_fill(0, count($rsvpIds), '?'));
                        $partyRows = $db->query(
                            "SELECT rfm.rsvp_id, rfm.family_member_id, fm.first_name, fm.last_name, fm.date_of_birth,
                                    fm.relationship, fm.linked_user_id
                             FROM rsvp_family_members rfm
                             INNER JOIN family_members fm ON fm.id = rfm.family_member_id
                             WHERE rfm.rsvp_id IN ($ph)",
                            $rsvpIds
                        );
                        $byRsvp = [];
                        foreach ($partyRows as $pr) {
                            $rid = (int) $pr['rsvp_id'];
                            if (!isset($byRsvp[$rid])) {
                                $byRsvp[$rid] = [];
                            }
                            $byRsvp[$rid][] = $pr;
                        }
                        $hasFmAtt = $db->hasColumn('attendance', 'family_member_id');
                        foreach ($rsvps as &$rsvpRow) {
                            $rid = (int) ($rsvpRow['id'] ?? 0);
                            $primaryUid = (int) ($rsvpRow['user_id'] ?? 0);
                            $included = [];
                            foreach ($byRsvp[$rid] ?? [] as $fm) {
                                $checked = false;
                                $checkedAt = null;
                                $fmId = (int) $fm['family_member_id'];
                                $linked = !empty($fm['linked_user_id']) ? (int) $fm['linked_user_id'] : 0;
                                if ($linked > 0) {
                                    $att = $db->queryOne(
                                        "SELECT checked_in_at FROM attendance
                                         WHERE checked_in_at IS NOT NULL AND DATE(checked_in_at) = :ed
                                           AND event_id IN (:eid, :pid) AND user_id = :uid"
                                        . ($hasFmAtt ? ' AND IFNULL(family_member_id, 0) = 0' : ''),
                                        ['ed' => $eventDateYmd, 'eid' => $eventId, 'pid' => $parentForAttendance, 'uid' => $linked]
                                    );
                                } else {
                                    $sqlA = "SELECT checked_in_at FROM attendance
                                         WHERE checked_in_at IS NOT NULL AND DATE(checked_in_at) = :ed
                                           AND event_id IN (:eid, :pid) AND user_id = :uid";
                                    $parA = ['ed' => $eventDateYmd, 'eid' => $eventId, 'pid' => $parentForAttendance, 'uid' => $primaryUid];
                                    if ($hasFmAtt) {
                                        $sqlA .= ' AND family_member_id = :fmid';
                                        $parA['fmid'] = $fmId;
                                    }
                                    $att = $db->queryOne($sqlA, $parA);
                                }
                                if (!empty($att['checked_in_at'])) {
                                    $checked = true;
                                    $checkedAt = $att['checked_in_at'];
                                }
                                $included[] = [
                                    'family_member_id' => $fmId,
                                    'first_name' => $fm['first_name'],
                                    'last_name' => $fm['last_name'],
                                    'relationship' => $fm['relationship'] ?? null,
                                    'linked_user_id' => $linked > 0 ? $linked : null,
                                    'checked_in' => $checked,
                                    'checked_in_at' => $checkedAt,
                                ];
                            }
                            $rsvpRow['included_family_members'] = $included;
                        }
                        unset($rsvpRow);
                    }
                }
            } catch (\Throwable $e) {
                error_log('RSVP party enrichment: ' . $e->getMessage());
            }
        }

        if (!isset($eventQuestions) || !is_array($eventQuestions)) {
            $eventQuestions = [];
        }
        foreach ($eventQuestions as &$questionRow) {
            $questionRow = [
                'id' => isset($questionRow['id']) ? (int) $questionRow['id'] : 0,
                'question_text' => isset($questionRow['question_text']) ? (string) $questionRow['question_text'] : '',
                'sort_order' => isset($questionRow['sort_order']) ? (int) $questionRow['sort_order'] : 0,
            ];
        }
        unset($questionRow);

        jsonResponse(['success' => true, 'rsvps' => $rsvps, 'summary' => $summary, 'event_questions' => $eventQuestions]);
        exit;
    }

    // GET event-invites — invited members for invite-only flow (RSVP source event id)
    if ($action === 'event-invites' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $eid = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0);
        if ($eid <= 0) {
            jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
            exit;
        }
        $ev = $db->queryOne(
            'SELECT id FROM events WHERE id = ? AND organization_id = ?',
            [$eid, $organizationId]
        );
        if (!$ev) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            exit;
        }
        $inviteSvc = new EventInviteService();
        $invites = $inviteSvc->listInvitesForViewEvent($db, $organizationId, $eid);
        foreach ($invites as &$invRow) {
            $invRow['profile_incomplete'] = empty($invRow['password_hash']);
            unset($invRow['password_hash']);
        }
        unset($invRow);
        jsonResponse(['success' => true, 'invites' => $invites, 'invite_storage_event_id' => EventInviteService::inviteStorageEventId($db, $eid)]);
        exit;
    }

    // POST add-event-invites — body: { id: eventId, user_ids: [int,...] }
    if ($action === 'add-event-invites' && isPost()) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON'], 400);
            exit;
        }
        $eid = isset($body['id']) ? (int) $body['id'] : 0;
        $userIds = isset($body['user_ids']) && is_array($body['user_ids']) ? $body['user_ids'] : [];
        if ($eid <= 0 || empty($userIds)) {
            jsonResponse(['success' => false, 'message' => 'Event ID and user_ids are required'], 400);
            exit;
        }
        $ev = $db->queryOne(
            'SELECT id FROM events WHERE id = ? AND organization_id = ?',
            [$eid, $organizationId]
        );
        if (!$ev) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            exit;
        }
        $inviteSvc = new EventInviteService();
        $res = $inviteSvc->addInvitesForViewEvent($db, $organizationId, $eid, $userIds, $userId, $body['note'] ?? null);
        $invites = $inviteSvc->listInvitesForViewEvent($db, $organizationId, $eid);
        foreach ($invites as &$invRow) {
            $invRow['profile_incomplete'] = empty($invRow['password_hash']);
            unset($invRow['password_hash']);
        }
        unset($invRow);
        jsonResponse([
            'success' => true,
            'added' => $res['added'],
            'skipped' => $res['skipped'],
            'invites' => $invites,
        ]);
        exit;
    }

    // POST remove-event-invite — body: { id: eventId, invite_id: row id }
    if ($action === 'remove-event-invite' && isPost()) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON'], 400);
            exit;
        }
        $eid = isset($body['id']) ? (int) $body['id'] : 0;
        $inviteRowId = isset($body['invite_id']) ? (int) $body['invite_id'] : 0;
        if ($eid <= 0 || $inviteRowId <= 0) {
            jsonResponse(['success' => false, 'message' => 'Event ID and invite_id are required'], 400);
            exit;
        }
        $ev = $db->queryOne(
            'SELECT id FROM events WHERE id = ? AND organization_id = ?',
            [$eid, $organizationId]
        );
        if (!$ev) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            exit;
        }
        $inviteSvc = new EventInviteService();
        $ok = $inviteSvc->removeInviteForViewEvent($db, $organizationId, $eid, $inviteRowId);
        jsonResponse(['success' => $ok, 'message' => $ok ? 'Invite removed' : 'Invite not found']);
        exit;
    }

    // POST invite-guest-by-email — body: { id: eventId, email, first_name, last_name }
    if ($action === 'invite-guest-by-email' && isPost()) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON'], 400);
            exit;
        }
        $eid = isset($body['id']) ? (int) $body['id'] : 0;
        $email = trim((string) ($body['email'] ?? ''));
        $firstName = trim((string) ($body['first_name'] ?? ''));
        $lastName = trim((string) ($body['last_name'] ?? ''));
        if ($eid <= 0 || $email === '') {
            jsonResponse(['success' => false, 'message' => 'Event ID and email are required'], 400);
            exit;
        }
        $ev = $db->queryOne(
            'SELECT * FROM events WHERE id = ? AND organization_id = ?',
            [$eid, $organizationId]
        );
        if (!$ev) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            exit;
        }
        $inviteSvc = new EventInviteService();
        $res = $inviteSvc->inviteGuestByEmailForViewEvent(
            $db,
            $organizationId,
            $eid,
            $email,
            $firstName,
            $lastName,
            $userId
        );
        if (empty($res['success'])) {
            jsonResponse(['success' => false, 'message' => $res['message'] ?? 'Could not invite guest.'], 400);
            exit;
        }

        $emailSent = false;
        $emailError = null;
        if ((int) ($res['added'] ?? 0) > 0 && !empty($res['user']) && is_array($res['user'])) {
            $guestUser = $res['user'];
            $needsProfile = !empty($res['needs_profile']);
            $org = $db->queryOne(
                'SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?',
                [$organizationId]
            );
            $orgConfig = null;
            if ($org && !empty($org['smtp_from_email'])) {
                $apiKey = null;
                if (!empty($org['smtp_api_key'])) {
                    $apiKey = base64_decode($org['smtp_api_key'], true);
                }
                if (($apiKey === false || $apiKey === '') && !empty($org['smtp_api_key_encrypted'])) {
                    $encKey = $config['security']['encryption_key'] ?? null;
                    if ($encKey) {
                        $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $encKey);
                    }
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
            if (!$orgConfig && !empty($config['smtp2go'])) {
                $orgConfig = $config['smtp2go'];
            }
            if ($orgConfig) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                $basePathForUrl = preg_replace('#/api/.*$#', '', $scriptName);
                $basePathForUrl = rtrim(str_replace('\\', '/', dirname(dirname($basePathForUrl))), '/');
                $portalBase = $protocol . '://' . $host . $basePathForUrl;
                $guestEmail = (string) ($guestUser['email'] ?? $email);
                $registerUrl = $portalBase . '/portal/register.php?email=' . urlencode($guestEmail);
                $eventPortalUrl = $portalBase . '/portal/event-details.php?id=' . $eid;
                try {
                    $emailService = new PortalEmailService($orgConfig);
                    $sendResult = $emailService->sendEventInviteNotification(
                        $ev,
                        $guestUser,
                        $eventPortalUrl,
                        $registerUrl,
                        $needsProfile
                    );
                    $emailSent = !empty($sendResult['success']);
                    if (!$emailSent) {
                        $emailError = $sendResult['message'] ?? 'Email could not be sent.';
                    }
                } catch (\Throwable $e) {
                    error_log('invite-guest-by-email notification: ' . $e->getMessage());
                    $emailError = 'Email could not be sent.';
                }
            } else {
                $emailError = 'Email service not configured.';
            }
        }

        $invites = $inviteSvc->listInvitesForViewEvent($db, $organizationId, $eid);
        foreach ($invites as &$invRow) {
            $invRow['profile_incomplete'] = empty($invRow['password_hash']);
            unset($invRow['password_hash']);
        }
        unset($invRow);

        jsonResponse([
            'success' => true,
            'added' => (int) ($res['added'] ?? 0),
            'skipped' => (int) ($res['skipped'] ?? 0),
            'message' => $res['message'] ?? null,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
            'invites' => $invites,
        ]);
        exit;
    }

    // CREATE event
    if ($action === 'create' && isPost()) {
    // Handle both JSON and multipart/form-data (detect multipart by Content-Type, not $_FILES — fileless multipart must still use $_POST)
    $input = [];
    if (isMultipartFormRequest()) {
        $input = $_POST;
    } else {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()], 400);
        }
    }
    
    $errors = [];
    
    // Validate
    if (empty($input['title'])) {
        $errors[] = 'Event title is required.';
    }
    
    if (empty($input['event_date'])) {
        $errors[] = 'Event date is required.';
    } elseif (strtotime($input['event_date']) < strtotime('today midnight')) {
        $errors[] = 'Event date cannot be in the past.';
    }
    
    if (empty($input['location'])) {
        $errors[] = 'Location is required.';
    }

    $errors = array_merge($errors, headcount_event_facility_api_errors($db, (int) $organizationId, $input));
    
    // Validate categories (support both old single category and new multiple categories)
    $categories = $input['categories'] ?? [];
    if (empty($categories) && empty($input['category'])) {
        $errors[] = 'At least one category is required.';
    }
    
    if (!empty($errors)) {
        jsonResponse(['success' => false, 'errors' => $errors], 400);
    }
    
    try {
        // Clear any output before database operations
        while (ob_get_level() > 0) {
            ob_clean();
        }

        $isRecurringRequest = requestBoolFromInput($input, 'is_recurring', false);
        
        // Use first category as legacy category field for backward compatibility
        $legacyCategory = !empty($categories) ? (is_numeric($categories[0]) ? 'other' : $categories[0]) : ($input['category'] ?? 'other');

        if ($isRecurringRequest) {
            $rt = isset($input['recurrence_type']) ? trim(strtolower((string) $input['recurrence_type'])) : 'weekly';
            if ($rt === 'weekly' && !recurrenceDaysProvided($input)) {
                jsonResponse(['success' => false, 'errors' => ['Select at least one weekday for weekly recurring events (Sunday counts as a weekday).']], 400);
                exit;
            }
        }
        
        // Handle banner image upload
        $bannerImagePath = null;
        $uploadError = null;
        if (!empty($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $uploadConfig = $config['uploads'] ?? [];
                $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $uploadConfig['max_size'] = 5242880; // 5MB for banner images
                
                // Ensure upload_path is set correctly and is absolute
                if (empty($uploadConfig['upload_path'])) {
                    $uploadConfig['upload_path'] = __DIR__ . '/../../uploads/';
                }
                // Normalize path
                $uploadConfig['upload_path'] = rtrim(realpath($uploadConfig['upload_path']) ?: $uploadConfig['upload_path'], '/\\') . '/';
                
                $fileUpload = new FileUpload($uploadConfig);
                $uploadResult = $fileUpload->upload($_FILES['banner_image'], 'event-banners');
                $bannerImagePath = 'event-banners/' . $uploadResult['filename'];
                
                // Verify file was actually created
                $fullPath = $uploadConfig['upload_path'] . $bannerImagePath;
                if (!file_exists($fullPath) || !is_file($fullPath)) {
                    $uploadError = "Banner image file was not saved correctly";
                    error_log("Banner image file not found after upload: " . $fullPath);
                    error_log("Upload result: " . print_r($uploadResult, true));
                    $bannerImagePath = null;
                } else {
                    error_log("Banner image successfully uploaded to: " . $fullPath);
                }
            } catch (\Exception $e) {
                $uploadError = "Banner image upload failed: " . $e->getMessage();
                error_log("Banner image upload error: " . $e->getMessage());
                error_log("Upload file info: " . print_r($_FILES['banner_image'] ?? [], true));
                error_log("Stack trace: " . $e->getTraceAsString());
                $bannerImagePath = null;
            }
        } elseif (!empty($_FILES['banner_image'])) {
            // File was sent but has an error
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            $errorCode = $_FILES['banner_image']['error'] ?? UPLOAD_ERR_NO_FILE;
            $uploadError = $errorMessages[$errorCode] ?? 'Unknown upload error';
            error_log("Banner image upload error code: " . $errorCode . " - " . $uploadError);
        }
        
        $eventData = [
            'organization_id' => $organizationId,
            'title' => sanitizePlainText((string) $input['title']),
            'description' => $input['description'] ?? null,
            'event_date' => $input['event_date'],
            'start_time' => $input['start_time'] ?: null,
            'end_time' => $input['end_time'] ?: null,
            'location' => sanitizePlainText((string) $input['location']),
            'category' => $legacyCategory,
            'capacity' => !empty($input['capacity']) ? (int)$input['capacity'] : null,
            'ticket_price' => isset($input['ticket_price']) ? (float)$input['ticket_price'] : 0.00,
            'registration_required' => !empty($input['registration_required']) ? 1 : 0,
            'status' => $input['status'] ?? 'draft',
            'created_by' => $userId
        ];
        
        // Check which optional columns exist and add them if they do
        try {
            $columns = $db->query("SHOW COLUMNS FROM events");
            $columnNames = array_column($columns, 'Field');
            
            // Add banner_image if column exists
            if (in_array('banner_image', $columnNames)) {
                $eventData['banner_image'] = $bannerImagePath;
            }
            
            // Add checkin_window_start if column exists
            if (in_array('checkin_window_start', $columnNames)) {
                $eventData['checkin_window_start'] = !empty($input['checkin_window_start']) ? $input['checkin_window_start'] : null;
            }
            
            // Add checkin_window_end if column exists
            if (in_array('checkin_window_end', $columnNames)) {
                $eventData['checkin_window_end'] = !empty($input['checkin_window_end']) ? $input['checkin_window_end'] : null;
            }
            // Add registration_deadline if column exists
            if (in_array('registration_deadline', $columnNames)) {
                $eventData['registration_deadline'] = !empty($input['registration_deadline']) ? $input['registration_deadline'] : null;
            }
            if (in_array('min_age', $columnNames)) {
                $eventData['min_age'] = isset($input['min_age']) && $input['min_age'] !== '' && $input['min_age'] !== null
                    ? (int) $input['min_age'] : null;
            }
            if (in_array('max_age', $columnNames)) {
                $eventData['max_age'] = isset($input['max_age']) && $input['max_age'] !== '' && $input['max_age'] !== null
                    ? (int) $input['max_age'] : null;
            }
            if (in_array('gender_restriction', $columnNames)) {
                $gr = isset($input['gender_restriction']) ? trim((string) $input['gender_restriction']) : 'none';
                if (!in_array($gr, ['none', 'male', 'female', 'other'], true)) {
                    $gr = 'none';
                }
                $eventData['gender_restriction'] = $gr;
            }
            if (in_array('enforce_restrictions_at_checkin', $columnNames)) {
                $eventData['enforce_restrictions_at_checkin'] = !empty($input['enforce_restrictions_at_checkin']) ? 1 : 0;
            }
            // Add allow_guest_rsvp if column exists
            if (in_array('allow_guest_rsvp', $columnNames)) {
                $eventData['allow_guest_rsvp'] = !empty($input['allow_guest_rsvp']) ? 1 : 0;
            }
            if (in_array('is_potluck', $columnNames)) {
                $eventData['is_potluck'] = !empty($input['is_potluck']) ? 1 : 0;
            }
            if (in_array('collect_feedback', $columnNames)) {
                $eventData['collect_feedback'] = !empty($input['collect_feedback']) ? 1 : 0;
            }
            if (in_array('potluck_show_bringing_prompt', $columnNames)) {
                $eventData['potluck_show_bringing_prompt'] = isset($input['potluck_show_bringing_prompt'])
                    ? (!empty($input['potluck_show_bringing_prompt']) ? 1 : 0)
                    : 1;
            }
            // Add is_virtual and extra_details if columns exist
            if (in_array('is_virtual', $columnNames)) {
                $eventData['is_virtual'] = !empty($input['is_virtual']) ? 1 : 0;
            }
            if (in_array('extra_details', $columnNames)) {
                $eventData['extra_details'] = isset($input['extra_details']) ? (string)$input['extra_details'] : null;
            }
            if (in_array('session_registration_mode', $columnNames)) {
                $m = isset($input['session_registration_mode']) ? trim((string) $input['session_registration_mode']) : 'independent';
                if (!in_array($m, ['independent', 'choose_one', 'all_sessions'], true)) {
                    $m = 'independent';
                }
                $eventData['session_registration_mode'] = $m;
            }

            $tierSvc = new EventHeadcountPricingService();
            $pricingModel = (!empty($input['pricing_model']) && $input['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER)
                ? EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
                : EventHeadcountPricingService::MODEL_PER_PERSON;
            if (in_array('pricing_model', $columnNames)) {
                $eventData['pricing_model'] = $pricingModel;
            }
            if (in_array('headcount_pricing_tiers', $columnNames)) {
                $normTiers = $tierSvc->normalizeTiersFromInput($input['headcount_pricing_tiers'] ?? $input['headcount_pricing_tiers_json'] ?? null);
                if ($normTiers['error'] !== null) {
                    jsonResponse(['success' => false, 'message' => $normTiers['error']], 400);
                    exit;
                }
                if ($pricingModel === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER) {
                    $vErr = $tierSvc->validateTiersForSave($normTiers['tiers']);
                    if ($vErr !== null) {
                        jsonResponse(['success' => false, 'message' => $vErr], 400);
                        exit;
                    }
                    $eventData['headcount_pricing_tiers'] = json_encode($normTiers['tiers']);
                } else {
                    $eventData['headcount_pricing_tiers'] = null;
                }
            }
            if (in_array('visibility', $columnNames)) {
                $eventData['visibility'] = EventVisibilityService::normalize($input['visibility'] ?? 'public');
            }
            if ((in_array('facility_id', $columnNames, true) || $db->tableExists('event_facilities'))
                && (array_key_exists('facility_id', $input) || array_key_exists('facility_ids', $input))) {
                $resolvedIds = headcount_resolve_event_facility_ids(
                    $db,
                    (int) $organizationId,
                    $input['facility_ids'] ?? ($input['facility_id'] ?? [])
                );
                $eventData['facility_id'] = ($resolvedIds === false || $resolvedIds === []) ? null : $resolvedIds[0];
            }
        } catch (\Exception $e) {
            // If we can't check columns, log error but continue without optional fields
            error_log("Could not check table columns: " . $e->getMessage());
        }
        
        $eventId = $db->insert('events', $eventData);
        if (array_key_exists('facility_ids', $input) || array_key_exists('facility_id', $input)) {
            $syncIds = headcount_resolve_event_facility_ids($db, (int) $organizationId, $input['facility_ids'] ?? ($input['facility_id'] ?? []));
            (new \Headcount\Services\EventFacilityService($db))->syncEvent(
                (int) $eventId,
                (int) $organizationId,
                $syncIds === false ? [] : $syncIds
            );
        }
        
        // Log upload error if it occurred (but don't fail event creation)
        if ($uploadError && $bannerImagePath) {
            // If we have an error but also a path, something is wrong - clear the path
            $db->update('events', $eventId, ['banner_image' => null]);
            error_log("Event created but banner image path cleared due to upload error: " . $uploadError);
        } elseif ($uploadError) {
            error_log("Event created without banner image due to: " . $uploadError);
        }
        
        // Save event categories
        if (!empty($categories)) {
            try {
                foreach ($categories as $categoryId) {
                    if (is_numeric($categoryId)) {
                        $db->insert('event_categories', [
                            'event_id' => $eventId,
                            'category_id' => (int)$categoryId
                        ]);
                    }
                }
            } catch (Exception $e) {
                // event_categories table might not exist yet, continue without error
            }
        }
        
        // Save event custom questions (with options and conditionals)
        $validQuestionTypes = ['text', 'short_text', 'checkbox', 'number', 'radio', 'dropdown', 'multi_checkbox'];
        $questions = $input['questions'] ?? [];
        if (is_string($questions)) $questions = json_decode($questions, true) ?: [];
        if (is_array($questions)) {
            try {
                $hasDependsOnColumns = $db->hasColumn('event_questions', 'depends_on_question_id');
                $orderedIds = [];
                foreach ($questions as $idx => $q) {
                    if (empty($q['question_text'])) continue;
                    $qType = isset($q['question_type']) && in_array($q['question_type'], $validQuestionTypes, true)
                        ? $q['question_type'] : 'short_text';
                    $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
                    $qType = headcount_normalize_event_question_type($qType, $options);
                    if (in_array($qType, ['radio', 'dropdown', 'multi_checkbox'], true) && headcount_event_question_option_labels($options) === []) {
                        continue; // require at least one option for choice types
                    }
                    $insertData = [
                        'event_id' => $eventId,
                        'question_text' => substr(trim($q['question_text']), 0, 500),
                        'question_type' => $qType,
                        'is_required' => !empty($q['is_required']) ? 1 : 0,
                        'sort_order' => isset($q['sort_order']) ? (int)$q['sort_order'] : $idx
                    ];
                    if ($hasDependsOnColumns) {
                        $insertData['depends_on_question_id'] = null;
                        $insertData['depends_on_value'] = null;
                    }
                    $questionId = $db->insert('event_questions', $insertData);
                    $orderedIds[] = $questionId;
                    if ($questionId && !empty($options)) {
                        foreach ($options as $oi => $opt) {
                            $label = isset($opt['option_label']) ? trim($opt['option_label']) : (is_string($opt) ? trim($opt) : '');
                            if ($label === '') continue;
                            $db->insert('event_question_options', [
                                'question_id' => $questionId,
                                'option_label' => substr($label, 0, 255),
                                'sort_order' => isset($opt['sort_order']) ? (int)$opt['sort_order'] : $oi
                            ]);
                        }
                    }
                }
                // Second pass: set depends_on_question_id and depends_on_value (resolve __idx_N to new IDs)
                $saveIndex = 0;
                foreach ($questions as $idx => $q) {
                    if (empty($q['question_text'])) continue;
                    $qType = isset($q['question_type']) && in_array($q['question_type'], $validQuestionTypes, true)
                        ? $q['question_type'] : 'short_text';
                    $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
                    $qType = headcount_normalize_event_question_type($qType, $options);
                    if (in_array($qType, ['radio', 'dropdown', 'multi_checkbox'], true) && headcount_event_question_option_labels($options) === []) {
                        continue;
                    }
                    $questionId = isset($orderedIds[$saveIndex]) ? $orderedIds[$saveIndex] : null;
                    if (!$questionId) {
                        $saveIndex++;
                        continue;
                    }
                    $rawDep = isset($q['depends_on_question_id']) ? $q['depends_on_question_id'] : null;
                    if ($rawDep === '' || $rawDep === null) {
                        $dependsOnId = null;
                    } elseif (is_string($rawDep) && preg_match('/^__idx_(\d+)$/', $rawDep, $m)) {
                        $dependsOnId = isset($orderedIds[(int)$m[1]]) ? (int)$orderedIds[(int)$m[1]] : null;
                    } else {
                        $dependsOnId = (int)$rawDep;
                        if ($dependsOnId <= 0) $dependsOnId = null;
                    }
                    $dependsOnValue = isset($q['depends_on_value']) && $q['depends_on_value'] !== '' && $q['depends_on_value'] !== null
                        ? substr(trim((string)$q['depends_on_value']), 0, 500) : null;
                    if ($hasDependsOnColumns) {
                        $db->update('event_questions', $questionId, [
                            'depends_on_question_id' => $dependsOnId,
                            'depends_on_value' => $dependsOnValue
                        ]);
                    }
                    $saveIndex++;
                }
            } catch (\Exception $e) {
                // event_questions / event_question_options may not exist yet
                error_log("Could not save event questions: " . $e->getMessage());
            }
        }

        // Save event ticket types
        $ticketTypes = $input['ticket_types'] ?? [];
        if (is_string($ticketTypes)) {
            $ticketTypes = json_decode($ticketTypes, true) ?: [];
        }
        if (!is_array($ticketTypes)) {
            $ticketTypes = [];
        }
        try {
            EventTicketTypesPersistence::replaceTicketTypesForEvent($db, (int) $eventId, $ticketTypes);
        } catch (\Exception $e) {
            error_log('Could not save event ticket types: ' . $e->getMessage());
        }

        $eventPeopleSvcCreate = new EventPeopleService();
        if ($eventPeopleSvcCreate->tableExists()) {
            try {
                $eventPeopleSvcCreate->replaceFromAdminInput((int) $eventId, $input, $_FILES ?? [], $config);
            } catch (\Throwable $e) {
                error_log('event_people create: ' . $e->getMessage());
            }
        }
        
        // Handle recurring events
        $generatedCount = 0;
        if ($isRecurringRequest) {
            try {
                require_once __DIR__ . '/../../src/Services/RecurringEventService.php';
                $recurrenceType = isset($input['recurrence_type']) ? trim(strtolower((string)$input['recurrence_type'])) : 'weekly';
                $weekOfMonth = null;
                if (isset($input['recurrence_week_of_month']) && $input['recurrence_week_of_month'] !== '' && $input['recurrence_week_of_month'] !== null) {
                    $v = (int) $input['recurrence_week_of_month'];
                    if ($v >= 1 && $v <= 5) $weekOfMonth = $v;
                }
                $daysOfWeek = null;
                if (!empty($input['recurrence_days'])) {
                    $daysOfWeek = is_array($input['recurrence_days']) ? implode(',', array_map('intval', $input['recurrence_days'])) : trim((string)$input['recurrence_days']);
                }
                $customDatesJson = null;
                if ($recurrenceType === 'custom') {
                    if (!$db->hasColumn('recurring_events', 'custom_dates')) {
                        jsonResponse(['success' => false, 'message' => 'Specific dates require database migration 037 (recurring_events.custom_dates).'], 400);
                        exit;
                    }
                    $encRes = \Headcount\Services\RecurringEventService::encodeCustomDatesFromInputResult($input, $input['event_date'] ?? null);
                    if (!empty($encRes['error'])) {
                        jsonResponse(['success' => false, 'message' => $encRes['error']], 400);
                        exit;
                    }
                    $customDatesJson = $encRes['json'] ?? null;
                    if ($customDatesJson === null) {
                        jsonResponse(['success' => false, 'message' => 'For “Specific dates”, add at least one additional session date (besides the main event date).'], 400);
                        exit;
                    }
                }
                $recurrenceData = [
                    'parent_event_id' => $eventId,
                    'organization_id' => $organizationId,
                    'recurrence_type' => $recurrenceType,
                    'interval' => (int)($input['recurrence_interval'] ?? 1),
                    'end_type' => isset($input['recurrence_end_type']) ? trim((string)$input['recurrence_end_type']) : 'never',
                    'end_after_count' => !empty($input['recurrence_end_after_count']) ? (int)$input['recurrence_end_after_count'] : null,
                    'end_date' => !empty($input['recurrence_end_date']) ? $input['recurrence_end_date'] : null,
                    'days_of_week' => $daysOfWeek !== '' ? $daysOfWeek : null,
                    'week_of_month' => $weekOfMonth
                ];
                if ($db->hasColumn('recurring_events', 'custom_dates')) {
                    $recurrenceData['custom_dates'] = $recurrenceType === 'custom' ? $customDatesJson : null;
                }
                $db->insert('recurring_events', $recurrenceData);
                
                // Generate initial instances
                if (!$recurringServiceClass) {
                    throw new Exception('RecurringEventService not available');
                }
                $recurringService = new $recurringServiceClass();
                $generatePayload = [
                    'recurrence_type' => $recurrenceData['recurrence_type'],
                    'interval' => $recurrenceData['interval'],
                    'end_type' => $recurrenceData['end_type'],
                    'end_after_count' => $recurrenceData['end_after_count'],
                    'end_date' => $recurrenceData['end_date'],
                    'days_of_week' => $recurrenceData['days_of_week']
                ];
                if ($recurrenceData['week_of_month'] !== null) {
                    $generatePayload['week_of_month'] = $recurrenceData['week_of_month'];
                }
                if ($recurrenceType === 'custom' && $customDatesJson !== null) {
                    $generatePayload['custom_dates'] = $customDatesJson;
                }
                $generatedIds = $recurringService->generateInstances($eventId, $generatePayload);
                if ($recurrenceType === 'custom' && $customDatesJson !== null) {
                    \Headcount\Services\RecurringEventService::pruneStaleCustomSeriesChildren($db, (int) $eventId, $customDatesJson);
                }
                
                $generatedCount = count($generatedIds);
            } catch (Exception $e) {
                error_log("Error creating recurring event: " . $e->getMessage());
                $errMsg = $e->getMessage();
                if (strpos($errMsg, 'recurrence_type') !== false || strpos($errMsg, 'Data truncated') !== false || strpos($errMsg, 'ENUM') !== false || strpos($errMsg, "doesn't exist") !== false) {
                    jsonResponse(['success' => false, 'message' => 'Recurring settings could not be saved. For "Monthly (e.g. last Friday)" run migrations 004 and 019.', 'errors' => ['recurrence' => $errMsg]], 400);
                    exit;
                }
                jsonResponse(['success' => false, 'message' => 'Recurring settings could not be saved.', 'errors' => ['recurrence' => $errMsg]], 400);
                exit;
            }
        }
        
        $message = 'Event created successfully';
        if ($generatedCount > 0) {
            $message .= " with {$generatedCount} recurring instance(s)";
        }
        
        jsonResponse(['success' => true, 'event_id' => $eventId, 'generated_instances' => $generatedCount, 'message' => $message]);
        
    } catch (\PDOException $e) {
        // Database-specific errors
        error_log("Database error creating event: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Database error: Failed to create event. Please check the logs.'], 500);
    } catch (\Exception $e) {
        // General errors
        error_log("Error creating event: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to create event: ' . $e->getMessage()], 500);
    } catch (\Throwable $e) {
        // Catch any other throwable
        error_log("Throwable error creating event: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'An unexpected error occurred'], 500);
    }
}

    // UPDATE event
    if ($action === 'update' && isPost()) {
    $input = [];
    if (isMultipartFormRequest()) {
        $input = $_POST;
    } else {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()], 400);
        }
    }
    
    $errors = [];
    
    if (empty($input['id'])) {
        jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
    }
    
    // Verify event belongs to organization
    // Check if is_recurring_instance column exists
    $hasRecurringColumn = false;
    try {
        $columnCheck = $db->queryOne(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = 'events' 
             AND COLUMN_NAME = 'is_recurring_instance'"
        );
        $hasRecurringColumn = !empty($columnCheck);
    } catch (Exception $e) {
        $hasRecurringColumn = false;
    }
    
    $selectFields = $hasRecurringColumn ? "id, is_recurring_instance" : "id";
    $existing = $db->queryOne(
        "SELECT {$selectFields} FROM events WHERE id = :id AND organization_id = :org_id",
        ['id' => $input['id'], 'org_id' => $organizationId]
    );
    
    if (!$existing) {
        jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
    }
    
    // Ensure is_recurring_instance is set (default to false if column doesn't exist)
    $isRecurringInstance = ($hasRecurringColumn && isset($existing['is_recurring_instance'])) ? (bool)$existing['is_recurring_instance'] : false;
    
    // Validate
    if (empty($input['title'])) {
        $errors[] = 'Event title is required.';
    }
    
    if (empty($input['event_date'])) {
        $errors[] = 'Event date is required.';
    }
    
    if (empty($input['location'])) {
        $errors[] = 'Location is required.';
    }

    $errors = array_merge($errors, headcount_event_facility_api_errors($db, (int) $organizationId, $input));
    
    // Validate categories
    $categories = $input['categories'] ?? [];
    if (empty($categories) && empty($input['category'])) {
        $errors[] = 'At least one category is required.';
    }
    
    if (!empty($errors)) {
        jsonResponse(['success' => false, 'errors' => $errors], 400);
    }
    
    try {
        $isRecurringRequest = requestBoolFromInput($input, 'is_recurring', false);

        if ($isRecurringRequest && !$isRecurringInstance) {
            $rt = isset($input['recurrence_type']) ? trim(strtolower((string) $input['recurrence_type'])) : 'weekly';
            if ($rt === 'weekly' && !recurrenceDaysProvided($input)) {
                jsonResponse(['success' => false, 'errors' => ['Select at least one weekday for weekly recurring events (Sunday counts as a weekday).']], 400);
                exit;
            }
        }

        // Use first category as legacy category field for backward compatibility
        $legacyCategory = !empty($categories) ? (is_numeric($categories[0]) ? 'other' : $categories[0]) : ($input['category'] ?? 'other');
        
        // Handle banner image upload
        $existingEvent = $db->queryOne("SELECT banner_image, parent_event_id FROM events WHERE id = :id", ['id' => $input['id']]);
        $scheduleBefore = $db->queryOne(
            "SELECT title, event_date, start_time, end_time, location FROM events WHERE id = :id AND organization_id = :org_id",
            ['id' => $input['id'], 'org_id' => $organizationId]
        );
        $peopleTargetEventIdUpd = (int) $input['id'];
        if (!empty($existingEvent['parent_event_id'])) {
            $peopleTargetEventIdUpd = (int) $existingEvent['parent_event_id'];
        }
        $bannerImagePath = $existingEvent['banner_image'] ?? null;
        
        if (!empty($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $uploadConfig = $config['uploads'] ?? [];
                $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $uploadConfig['max_size'] = 5242880; // 5MB for banner images
                
                if (empty($uploadConfig['upload_path'])) {
                    $uploadConfig['upload_path'] = __DIR__ . '/../../uploads/';
                }
                $uploadConfig['upload_path'] = rtrim(realpath($uploadConfig['upload_path']) ?: $uploadConfig['upload_path'], '/\\') . '/';
                
                $fileUpload = new FileUpload($uploadConfig);
                $uploadResult = $fileUpload->upload($_FILES['banner_image'], 'event-banners');
                $bannerImagePath = 'event-banners/' . $uploadResult['filename'];
                
                $fullPath = $uploadConfig['upload_path'] . $bannerImagePath;
                if (!file_exists($fullPath) || !is_file($fullPath)) {
                    error_log("Banner image file not found after upload: " . $fullPath);
                    $bannerImagePath = $existingEvent['banner_image'] ?? null;
                } else {
                    if (!empty($existingEvent['banner_image'])) {
                        $oldBannerPath = $uploadConfig['upload_path'] . $existingEvent['banner_image'];
                        if (file_exists($oldBannerPath)) {
                            @unlink($oldBannerPath);
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("Banner image upload error: " . $e->getMessage());
                error_log("Upload file info: " . print_r($_FILES['banner_image'] ?? [], true));
                $bannerImagePath = $existingEvent['banner_image'] ?? null;
            }
        } elseif (isset($input['banner_image']) && $input['banner_image'] === '') {
            // Explicitly remove banner image
            if (!empty($existingEvent['banner_image'])) {
                $rmUploadPath = $config['uploads']['upload_path'] ?? __DIR__ . '/../../uploads/';
                $rmUploadPath = rtrim(realpath($rmUploadPath) ?: $rmUploadPath, '/\\') . '/';
                $oldBannerPath = $rmUploadPath . $existingEvent['banner_image'];
                if (file_exists($oldBannerPath)) {
                    @unlink($oldBannerPath);
                }
            }
            $bannerImagePath = null;
        }
        
        $updateData = [
            'title' => sanitizePlainText((string) $input['title']),
            'description' => $input['description'] ?? null,
            'banner_image' => $bannerImagePath,
            'event_date' => $input['event_date'],
            'start_time' => $input['start_time'] ?: null,
            'end_time' => $input['end_time'] ?: null,
            'location' => sanitizePlainText((string) $input['location']),
            'category' => $legacyCategory,
            'capacity' => !empty($input['capacity']) ? (int)$input['capacity'] : null,
            'ticket_price' => isset($input['ticket_price']) ? (float)$input['ticket_price'] : 0.00,
            'registration_required' => !empty($input['registration_required']) ? 1 : 0,
            'registration_deadline' => !empty($input['registration_deadline']) ? $input['registration_deadline'] : null,
            'status' => $input['status'] ?? 'draft',
            'checkin_window_start' => !empty($input['checkin_window_start']) ? $input['checkin_window_start'] : null,
            'checkin_window_end' => !empty($input['checkin_window_end']) ? $input['checkin_window_end'] : null
        ];

        $tierSvcUpd = new EventHeadcountPricingService();
        $pricingModelUpd = (!empty($input['pricing_model']) && $input['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER)
            ? EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
            : EventHeadcountPricingService::MODEL_PER_PERSON;
        $normTiersUpd = $tierSvcUpd->normalizeTiersFromInput($input['headcount_pricing_tiers'] ?? $input['headcount_pricing_tiers_json'] ?? null);
        if ($normTiersUpd['error'] !== null) {
            jsonResponse(['success' => false, 'message' => $normTiersUpd['error']], 400);
            exit;
        }
        if ($pricingModelUpd === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER) {
            $vErrUpd = $tierSvcUpd->validateTiersForSave($normTiersUpd['tiers']);
            if ($vErrUpd !== null) {
                jsonResponse(['success' => false, 'message' => $vErrUpd], 400);
                exit;
            }
        }

        try {
            $evCols = $db->query("SHOW COLUMNS FROM events");
            $evColNames = array_column($evCols, 'Field');
            if (in_array('allow_guest_rsvp', $evColNames)) {
                $updateData['allow_guest_rsvp'] = !empty($input['allow_guest_rsvp']) ? 1 : 0;
            }
            if (in_array('is_potluck', $evColNames)) {
                $updateData['is_potluck'] = !empty($input['is_potluck']) ? 1 : 0;
            }
            if (in_array('collect_feedback', $evColNames)) {
                $updateData['collect_feedback'] = !empty($input['collect_feedback']) ? 1 : 0;
            }
            if (in_array('potluck_show_bringing_prompt', $evColNames) && array_key_exists('potluck_show_bringing_prompt', $input)) {
                $updateData['potluck_show_bringing_prompt'] = !empty($input['potluck_show_bringing_prompt']) ? 1 : 0;
            }
            if (in_array('is_virtual', $evColNames)) {
                $updateData['is_virtual'] = !empty($input['is_virtual']) ? 1 : 0;
            }
            if (in_array('extra_details', $evColNames)) {
                $updateData['extra_details'] = isset($input['extra_details']) ? (string)$input['extra_details'] : null;
            }
            if (in_array('session_registration_mode', $evColNames)) {
                $m = isset($input['session_registration_mode']) ? trim((string) $input['session_registration_mode']) : 'independent';
                if (!in_array($m, ['independent', 'choose_one', 'all_sessions'], true)) {
                    $m = 'independent';
                }
                $updateData['session_registration_mode'] = $m;
            }
            if (in_array('pricing_model', $evColNames)) {
                $updateData['pricing_model'] = $pricingModelUpd;
            }
            if (in_array('headcount_pricing_tiers', $evColNames)) {
                $updateData['headcount_pricing_tiers'] = $pricingModelUpd === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
                    ? json_encode($normTiersUpd['tiers'])
                    : null;
            }
            if (in_array('min_age', $evColNames)) {
                $updateData['min_age'] = isset($input['min_age']) && $input['min_age'] !== '' && $input['min_age'] !== null
                    ? (int) $input['min_age'] : null;
            }
            if (in_array('max_age', $evColNames)) {
                $updateData['max_age'] = isset($input['max_age']) && $input['max_age'] !== '' && $input['max_age'] !== null
                    ? (int) $input['max_age'] : null;
            }
            if (in_array('gender_restriction', $evColNames)) {
                $gr = isset($input['gender_restriction']) ? trim((string) $input['gender_restriction']) : 'none';
                if (!in_array($gr, ['none', 'male', 'female', 'other'], true)) {
                    $gr = 'none';
                }
                $updateData['gender_restriction'] = $gr;
            }
            if (in_array('enforce_restrictions_at_checkin', $evColNames)) {
                $updateData['enforce_restrictions_at_checkin'] = !empty($input['enforce_restrictions_at_checkin']) ? 1 : 0;
            }
            if (in_array('visibility', $evColNames)) {
                $updateData['visibility'] = EventVisibilityService::normalize($input['visibility'] ?? 'public');
            }
            if ((in_array('facility_id', $evColNames, true) || $db->tableExists('event_facilities'))
                && (array_key_exists('facility_id', $input) || array_key_exists('facility_ids', $input))) {
                $resolvedIds = headcount_resolve_event_facility_ids(
                    $db,
                    (int) $organizationId,
                    $input['facility_ids'] ?? ($input['facility_id'] ?? [])
                );
                $updateData['facility_id'] = ($resolvedIds === false || $resolvedIds === []) ? null : $resolvedIds[0];
            }
        } catch (\Exception $e) { /* ignore */ }
        $db->update('events', $input['id'], $updateData);
        if (array_key_exists('facility_ids', $input) || array_key_exists('facility_id', $input)) {
            $syncIds = headcount_resolve_event_facility_ids($db, (int) $organizationId, $input['facility_ids'] ?? ($input['facility_id'] ?? []));
            $linkSvc = new \Headcount\Services\EventFacilityService($db);
            $linkSvc->syncEvent((int) $input['id'], (int) $organizationId, $syncIds === false ? [] : $syncIds);
            if (empty($existingEvent['parent_event_id']) && $db->hasColumn('events', 'parent_event_id')) {
                $childIds = $db->query(
                    'SELECT id FROM events WHERE parent_event_id = :pid AND organization_id = :org',
                    ['pid' => (int) $input['id'], 'org' => $organizationId]
                ) ?: [];
                foreach ($childIds as $child) {
                    $linkSvc->syncEvent((int) $child['id'], (int) $organizationId, $syncIds === false ? [] : $syncIds);
                }
            }
        }
        try {
            $isInstance = !empty($existingEvent['parent_event_id']);
            if (!$isInstance && $db->hasColumn('events', 'banner_image')) {
                $seriesRow = $db->queryOne(
                    "SELECT id FROM recurring_events WHERE parent_event_id = :id LIMIT 1",
                    ['id' => (int) $input['id']]
                );
                if ($seriesRow) {
                    $norm = static function ($v) {
                        if ($v === null || $v === '') {
                            return '';
                        }
                        return trim((string) $v);
                    };
                    if ($norm($existingEvent['banner_image'] ?? null) !== $norm($bannerImagePath)) {
                        $db->execute(
                            'UPDATE events SET banner_image = :img WHERE parent_event_id = :pid',
                            ['img' => $bannerImagePath, 'pid' => (int) $input['id']]
                        );
                    }
                    // Child instances created while the parent was draft stay draft; publish them when the series goes live
                    $parentStatus = $updateData['status'] ?? '';
                    if ($parentStatus === 'published') {
                        $db->execute(
                            "UPDATE events SET status = 'published' WHERE parent_event_id = :pid AND status = 'draft'",
                            ['pid' => (int) $input['id']]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('Sync child banner_image from recurring parent: ' . $e->getMessage());
        }
        
        // Update event categories
        if (!empty($categories)) {
            try {
                // Delete existing categories
                $db->execute("DELETE FROM event_categories WHERE event_id = :event_id", ['event_id' => $input['id']]);
                
                // Insert new categories
                foreach ($categories as $categoryId) {
                    if (is_numeric($categoryId)) {
                        $db->insert('event_categories', [
                            'event_id' => $input['id'],
                            'category_id' => (int)$categoryId
                        ]);
                    }
                }
            } catch (Exception $e) {
                // event_categories table might not exist yet, continue without error
            }
        }
        
        // Merge event custom questions (preserve IDs for conditionals), update options and depends_on
        $questions = $input['questions'] ?? [];
        if (is_string($questions)) {
            $questions = json_decode($questions, true) ?: [];
        }
        if (is_array($questions)) {
            try {
                (new EventQuestionMergeService($db))->mergeForEvent((int) $input['id'], $questions);
            } catch (\Exception $e) {
                error_log("Could not update event questions: " . $e->getMessage());
            }
        }

        // Replace event ticket types
        $ticketTypes = $input['ticket_types'] ?? [];
        if (is_string($ticketTypes)) {
            $ticketTypes = json_decode($ticketTypes, true) ?: [];
        }
        if (!is_array($ticketTypes)) {
            $ticketTypes = [];
        }
        try {
            EventTicketTypesPersistence::replaceTicketTypesForEvent($db, (int) $input['id'], $ticketTypes);
        } catch (\Exception $e) {
            error_log('Could not update event ticket types: ' . $e->getMessage());
        }

        $eventPeopleSvcUpd = new EventPeopleService();
        if ($eventPeopleSvcUpd->tableExists()) {
            try {
                $eventPeopleSvcUpd->replaceFromAdminInput($peopleTargetEventIdUpd, $input, $_FILES ?? [], $config);
            } catch (\Throwable $e) {
                error_log('event_people update: ' . $e->getMessage());
            }
        }
        
        // Update recurring event settings (only if not a recurring instance)
        if (!$isRecurringInstance && $isRecurringRequest) {
            try {
                if (!$recurringServiceClass) {
                    throw new Exception('RecurringEventService not available');
                }
                
                // Check if recurring_events record exists
                $recurring = $db->queryOne(
                    "SELECT id FROM recurring_events WHERE parent_event_id = :event_id",
                    ['event_id' => $input['id']]
                );
                
                $recurrenceType = isset($input['recurrence_type']) ? trim(strtolower((string)$input['recurrence_type'])) : 'weekly';
                $weekOfMonth = null;
                if (isset($input['recurrence_week_of_month']) && $input['recurrence_week_of_month'] !== '' && $input['recurrence_week_of_month'] !== null) {
                    $v = (int) $input['recurrence_week_of_month'];
                    if ($v >= 1 && $v <= 5) $weekOfMonth = $v;
                }
                $daysOfWeek = null;
                if (!empty($input['recurrence_days'])) {
                    $daysOfWeek = is_array($input['recurrence_days']) ? implode(',', array_map('intval', $input['recurrence_days'])) : trim((string)$input['recurrence_days']);
                }
                $customDatesJson = null;
                if ($recurrenceType === 'custom') {
                    if (!$db->hasColumn('recurring_events', 'custom_dates')) {
                        throw new Exception('Specific dates require database migration 037 (recurring_events.custom_dates).');
                    }
                    $encRes = \Headcount\Services\RecurringEventService::encodeCustomDatesFromInputResult($input, $input['event_date'] ?? null);
                    if (!empty($encRes['error'])) {
                        throw new Exception($encRes['error']);
                    }
                    $customDatesJson = $encRes['json'] ?? null;
                    if ($customDatesJson === null) {
                        throw new Exception('For “Specific dates”, add at least one additional session date (besides the main event date).');
                    }
                }
                $recurrenceData = [
                    'parent_event_id' => $input['id'],
                    'organization_id' => $organizationId,
                    'recurrence_type' => $recurrenceType,
                    'interval' => (int)($input['recurrence_interval'] ?? 1),
                    'end_type' => isset($input['recurrence_end_type']) ? trim((string)$input['recurrence_end_type']) : 'never',
                    'end_after_count' => !empty($input['recurrence_end_after_count']) ? (int)$input['recurrence_end_after_count'] : null,
                    'end_date' => !empty($input['recurrence_end_date']) ? $input['recurrence_end_date'] : null,
                    'days_of_week' => $daysOfWeek !== '' ? $daysOfWeek : null,
                    'week_of_month' => $weekOfMonth
                ];
                if ($db->hasColumn('recurring_events', 'custom_dates')) {
                    $recurrenceData['custom_dates'] = $recurrenceType === 'custom' ? $customDatesJson : null;
                }

                if ($recurring) {
                    $db->update('recurring_events', $recurring['id'], $recurrenceData);
                } else {
                    $db->insert('recurring_events', $recurrenceData);
                }

                $recurringService = new $recurringServiceClass();
                $generatePayload = [
                    'recurrence_type' => $recurrenceData['recurrence_type'],
                    'interval' => $recurrenceData['interval'],
                    'end_type' => $recurrenceData['end_type'],
                    'end_after_count' => $recurrenceData['end_after_count'],
                    'end_date' => $recurrenceData['end_date'],
                    'days_of_week' => $recurrenceData['days_of_week']
                ];
                if ($recurrenceData['week_of_month'] !== null) {
                    $generatePayload['week_of_month'] = $recurrenceData['week_of_month'];
                }
                if ($recurrenceType === 'custom' && $customDatesJson !== null) {
                    $generatePayload['custom_dates'] = $customDatesJson;
                }
                $recurringService->generateInstances((int) $input['id'], $generatePayload);
                if ($recurrenceType === 'custom' && $customDatesJson !== null) {
                    \Headcount\Services\RecurringEventService::pruneStaleCustomSeriesChildren($db, (int) $input['id'], $customDatesJson);
                }
            } catch (Exception $e) {
                error_log("Error updating recurring event: " . $e->getMessage());
                $errMsg = $e->getMessage();
                if (strpos($errMsg, 'recurrence_type') !== false || strpos($errMsg, 'Data truncated') !== false || strpos($errMsg, 'ENUM') !== false) {
                    jsonResponse(['success' => false, 'message' => 'Recurring settings could not be saved. For "Monthly (e.g. last Friday)" run database migration 019 (add_monthly_weekday_recurrence).', 'errors' => ['recurrence' => $errMsg]], 400);
                    exit;
                }
                jsonResponse(['success' => false, 'message' => 'Recurring settings could not be saved.', 'errors' => ['recurrence' => $errMsg]], 400);
                exit;
            }
        } elseif (!$isRecurringInstance) {
            // Remove recurring if unchecked
            try {
                $db->execute("DELETE FROM recurring_events WHERE parent_event_id = :event_id", ['event_id' => $input['id']]);
            } catch (Exception $e) {
                // Ignore if table doesn't exist
            }
        }
        
        if ($scheduleBefore) {
            $scheduleAfter = array_merge($scheduleBefore, [
                'title' => $updateData['title'],
                'event_date' => $updateData['event_date'],
                'start_time' => $updateData['start_time'],
                'end_time' => $updateData['end_time'],
                'location' => $updateData['location'],
            ]);
            $scheduleChanged = false;
            foreach (['event_date', 'start_time', 'end_time', 'location'] as $schedField) {
                $old = trim((string) ($scheduleBefore[$schedField] ?? ''));
                $new = trim((string) ($scheduleAfter[$schedField] ?? ''));
                if ($old !== $new) {
                    $scheduleChanged = true;
                    break;
                }
            }
            if ($scheduleChanged) {
                try {
                    \Headcount\Services\EventReminderService::clearSentRemindersForSeries($db, (int) $input['id']);
                } catch (\Throwable $e) {
                    error_log('Event reminder invalidation: ' . $e->getMessage());
                }
            }
            try {
                $notifier = new \Headcount\Services\ScheduleChangeNotificationService($config);
                $notifier->notifyEventIfScheduleChanged((int) $input['id'], $organizationId, $scheduleBefore, $scheduleAfter);
            } catch (\Throwable $e) {
                error_log('Event schedule change notification: ' . $e->getMessage());
            }
        }

        jsonResponse(['success' => true, 'message' => 'Event updated successfully']);
        
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to update event: ' . $e->getMessage()], 500);
    }
}

    // DUPLICATE event
    if ($action === 'duplicate' && isPost()) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()], 400);
    }
    
    if (empty($input['id'])) {
        jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
    }
    
    try {
        // Get original event
        $original = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND organization_id = :org_id",
            ['id' => $input['id'], 'org_id' => $organizationId]
        );
        
        if (!$original) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
        }
        
        // Create duplicate (include optional columns if present)
        $eventData = [
            'organization_id' => $organizationId,
            'title' => $original['title'] . ' (Copy)',
            'description' => $original['description'],
            'event_date' => $original['event_date'],
            'start_time' => $original['start_time'],
            'end_time' => $original['end_time'],
            'location' => $original['location'],
            'category' => $original['category'],
            'capacity' => $original['capacity'],
            'ticket_price' => $original['ticket_price'] ?? 0.00,
            'registration_required' => $original['registration_required'] ?? 0,
            'status' => 'draft',
            'created_by' => $userId
        ];
        try {
            $dupCols = $db->query("SHOW COLUMNS FROM events");
            $dupColNames = array_column($dupCols, 'Field');
            if (in_array('is_virtual', $dupColNames) && array_key_exists('is_virtual', $original)) {
                $eventData['is_virtual'] = (int)(!empty($original['is_virtual']));
            }
            if (in_array('extra_details', $dupColNames) && array_key_exists('extra_details', $original)) {
                $eventData['extra_details'] = $original['extra_details'];
            }
            if (in_array('pricing_model', $dupColNames) && array_key_exists('pricing_model', $original)) {
                $eventData['pricing_model'] = $original['pricing_model'];
            }
            if (in_array('headcount_pricing_tiers', $dupColNames) && array_key_exists('headcount_pricing_tiers', $original)) {
                $eventData['headcount_pricing_tiers'] = $original['headcount_pricing_tiers'];
            }
            if (in_array('visibility', $dupColNames) && array_key_exists('visibility', $original)) {
                $eventData['visibility'] = EventVisibilityService::normalize($original['visibility'] ?? 'public');
            }
            if (in_array('is_potluck', $dupColNames) && array_key_exists('is_potluck', $original)) {
                $eventData['is_potluck'] = (int) (!empty($original['is_potluck']));
            }
            if (in_array('potluck_allowed_slugs', $dupColNames) && array_key_exists('potluck_allowed_slugs', $original)) {
                $eventData['potluck_allowed_slugs'] = $original['potluck_allowed_slugs'];
            }
            if (in_array('potluck_show_bringing_prompt', $dupColNames) && array_key_exists('potluck_show_bringing_prompt', $original)) {
                $eventData['potluck_show_bringing_prompt'] = (int) (!empty($original['potluck_show_bringing_prompt']));
            }
        } catch (\Exception $e) { /* ignore */ }
        
        $newId = $db->insert('events', $eventData);

        $dupPeopleSvc = new EventPeopleService();
        if ($dupPeopleSvc->tableExists()) {
            try {
                $fromPid = EventPeopleService::peopleStorageEventId($original);
                $dupPeopleSvc->copyFromEvent($fromPid, (int) $newId);
            } catch (\Throwable $e) {
                error_log('event_people duplicate: ' . $e->getMessage());
            }
        }
        
        jsonResponse(['success' => true, 'event_id' => $newId, 'message' => 'Event duplicated successfully']);
        
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to duplicate event: ' . $e->getMessage()], 500);
    }
}

    // DELETE event
    if ($action === 'delete' && isPost()) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()], 400);
    }
    
    if (empty($input['id'])) {
        jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
    }
    
    try {
        // Verify event belongs to organization
        $event = $db->queryOne(
            "SELECT id FROM events WHERE id = :id AND organization_id = :org_id",
            ['id' => $input['id'], 'org_id' => $organizationId]
        );
        
        if (!$event) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
        }
        
        // Check if event has attendees
        $attendeeCount = $db->queryOne(
            "SELECT COUNT(*) as count FROM attendance WHERE event_id = :id",
            ['id' => $input['id']]
        )['count'] ?? 0;
        
        if ($attendeeCount > 0) {
            // Soft delete - change status to cancelled
            $event = $db->queryOne("SELECT title FROM events WHERE id = :id", ['id' => $input['id']]);
            $db->update('events', $input['id'], ['status' => 'cancelled']);
            
            // Create notification
            if ($event) {
                NotificationHelper::eventCancelled($organizationId, $input['id'], $event['title']);
            }
            
            jsonResponse(['success' => true, 'message' => 'Event cancelled and removed from the list. Attendance history is kept. View it under Status → Cancelled.']);
        } else {
            // Hard delete: recurring series parents have child rows + recurring_events — clean up first so FK/DB quirks never block removal
            if ($recurringServiceClass) {
                try {
                    $series = $db->queryOne(
                        'SELECT id FROM recurring_events WHERE parent_event_id = :id LIMIT 1',
                        ['id' => $input['id']]
                    );
                    if ($series) {
                        $svc = new $recurringServiceClass();
                        $svc->deleteAllInstances((int) $input['id']);
                    }
                } catch (\Exception $e) {
                    error_log('delete event: recurring cleanup: ' . $e->getMessage());
                }
            }
            $db->delete('events', $input['id'], 'id', false);
            jsonResponse(['success' => true, 'message' => 'Event deleted successfully']);
        }
        
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to delete event: ' . $e->getMessage()], 500);
    }
}

    // DELETE RSVP (admin only)
    if ($action === 'delete-rsvp' && isPost()) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()], 400);
        }

        if (empty($input['rsvp_id'])) {
            jsonResponse(['success' => false, 'message' => 'RSVP ID required'], 400);
        }

        $rsvpId = (int)$input['rsvp_id'];

        try {
            // Verify RSVP belongs to the same organization
            $rsvpRow = $db->queryOne(
                "SELECT r.id
                 FROM rsvps r
                 JOIN users u ON u.id = r.user_id
                 WHERE r.id = :rsvp_id
                   AND u.organization_id = :org_id",
                ['rsvp_id' => $rsvpId, 'org_id' => $organizationId]
            );

            if (!$rsvpRow) {
                jsonResponse(['success' => false, 'message' => 'RSVP not found'], 404);
            }

            // Delete question answers first (if table exists)
            try {
                $db->execute(
                    "DELETE FROM rsvp_question_answers WHERE rsvp_id = :rsvp_id",
                    ['rsvp_id' => $rsvpId]
                );
            } catch (\Exception $e) { /* ignore */ }

            $db->delete('rsvps', $rsvpId, 'id', false);

            jsonResponse(['success' => true, 'message' => 'RSVP removed successfully']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete RSVP: ' . $e->getMessage()], 500);
        }
    }

    // ANNOUNCE event
    if ($action === 'announce' && isPost()) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (empty($input['id'])) {
            jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
        }
        
        try {
            // Get event details
            $event = $db->queryOne(
                "SELECT * FROM events WHERE id = :id AND organization_id = :org_id",
                ['id' => $input['id'], 'org_id' => $organizationId]
            );
            
            if (!$event) {
                jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            }
            
            // Get all active members for this organization
            // Filter by email_preferences if column exists
            $members = $db->query(
                "SELECT id, first_name, last_name, email, email_preferences FROM users 
                 WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != ''",
                [$organizationId]
            );
            
            // Filter members who want announcements (event_announcements: true in email_preferences JSON)
            $recipients = [];
            foreach ($members as $member) {
                $prefs = !empty($member['email_preferences']) ? json_decode($member['email_preferences'], true) : null;
                if ($prefs === null || (isset($prefs['event_announcements']) && $prefs['event_announcements'])) {
                    $recipients[] = $member;
                }
            }
            
            if (empty($recipients)) {
                jsonResponse(['success' => false, 'message' => 'No eligible members found to receive announcement'], 400);
            }
            
            // Get organization SMTP and branding from database
            $org = $db->queryOne("SELECT smtp_api_key, smtp_from_email, smtp_from_name, name, logo_path FROM organizations WHERE id = ?", [$organizationId]);
            
            if (!$org || empty($org['smtp_api_key']) || empty($org['smtp_from_email'])) {
                jsonResponse(['success' => false, 'message' => 'Email service not configured. Please configure SMTP settings in Settings > Email (SMTP).'], 500);
            }
            
            // Decode API key (stored as base64 encoded)
            $apiKey = base64_decode($org['smtp_api_key'], true);
            if ($apiKey === false || empty($apiKey)) {
                jsonResponse(['success' => false, 'message' => 'Invalid API key. Please reconfigure your email settings.'], 500);
            }
            
            // Configure EmailService with organization settings
            $emailConfig = [
                'api_key' => $apiKey,
                'from_email' => $org['smtp_from_email'],
                'from_name' => $org['smtp_from_name'] ?? null
            ];
            
            $appUrl = rtrim($config['app']['url'] ?? '', '/');
            $logoUrl = !empty($org['logo_path']) ? buildLogoUrlForEmail($appUrl, $org['logo_path']) : null;
            $branding = ['logo_url' => $logoUrl, 'org_name' => $org['name'] ?? ''];
            
            // Log the announcement start
            $recipientIds = array_column($recipients, 'id');
            $recipientCount = count($recipientIds);
            
            $emailService = new \Headcount\Services\EmailService($emailConfig);

            $template = $db->queryOne(
                "SELECT subject, body_html FROM email_templates WHERE organization_id = ? AND template_type = 'announcement' LIMIT 1",
                [$organizationId]
            );
            if (!$template) {
                $template = $db->queryOne(
                    "SELECT subject, body_html FROM email_templates WHERE is_default = 1 AND template_type = 'announcement' LIMIT 1"
                );
            }

            $customSubject = isset($input['subject']) ? trim((string) $input['subject']) : '';
            $customBody = isset($input['body_html']) ? trim((string) $input['body_html']) : '';
            $options = [
                'template_type' => 'announcement',
                'subject' => $customSubject !== '' ? $customSubject : ($template['subject'] ?? 'Event Announcement: {event_name}'),
            ];
            if ($customBody !== '') {
                $options['body'] = $customBody;
            } elseif (!empty($template['body_html'])) {
                $options['body'] = $template['body_html'];
            }

            $results = $emailService->sendEventAnnouncement($event['id'], $organizationId, $recipientIds, $options, $branding);
            
            jsonResponse([
                'success' => true, 
                'message' => "Announcement sent to {$results['sent']} recipients" . ($results['failed'] > 0 ? " ({$results['failed']} failed)" : ''),
                'details' => [
                    'sent' => $results['sent'],
                    'failed' => $results['failed'],
                    'total' => $recipientCount
                ]
            ]);
            
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to send announcement: ' . $e->getMessage()], 500);
        }
        exit;
    }

    // SEND REMINDER (per event) – to registered attendees (RSVP yes) who have event_reminders enabled
    if ($action === 'remind' && isPost()) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (empty($input['id'])) {
            jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
        }

        try {
            $event = $db->queryOne(
                "SELECT * FROM events WHERE id = :id AND organization_id = :org_id",
                ['id' => $input['id'], 'org_id' => $organizationId]
            );

            if (!$event) {
                jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            }

            $rsvpSourceEventId = EventSeriesHelper::getRsvpSourceEventId($db, (int) $event['id']);
            $recipients = \Headcount\Services\EventReminderService::getRsvpYesRecipients($db, (int) $event['id'], true);

            if (empty($recipients)) {
                jsonResponse(['success' => false, 'message' => 'No eligible attendees found (RSVP yes with event reminders enabled).'], 400);
            }

            $org = $db->queryOne("SELECT smtp_api_key, smtp_from_email, smtp_from_name, name, logo_path FROM organizations WHERE id = ?", [$organizationId]);
            if (!$org || empty($org['smtp_api_key']) || empty($org['smtp_from_email'])) {
                jsonResponse(['success' => false, 'message' => 'Email service not configured. Please configure SMTP settings in Settings > Email (SMTP).'], 500);
            }

            $apiKey = base64_decode($org['smtp_api_key'], true);
            if ($apiKey === false || empty($apiKey)) {
                jsonResponse(['success' => false, 'message' => 'Invalid API key. Please reconfigure your email settings.'], 500);
            }

            $templateId = isset($input['template_id']) ? (int) $input['template_id'] : null;
            $customSubject = isset($input['subject']) ? trim((string) $input['subject']) : '';
            $customBody = isset($input['body_html']) ? trim((string) $input['body_html']) : '';

            $resolvedTemplate = \Headcount\Services\EventReminderService::resolveReminderTemplate(
                $db,
                $organizationId,
                '1day',
                ($templateId > 0) ? $templateId : null
            );

            $emailConfig = [
                'api_key' => $apiKey,
                'from_email' => $org['smtp_from_email'],
                'from_name' => $org['smtp_from_name'] ?? null
            ];
            $appUrl = rtrim($config['app']['url'] ?? '', '/');
            $logoUrl = !empty($org['logo_path']) ? buildLogoUrlForEmail($appUrl, $org['logo_path']) : null;
            $branding = ['logo_url' => $logoUrl, 'org_name' => $org['name'] ?? ''];
            $emailService = new \Headcount\Services\EmailService($emailConfig);

            $recipientIds = array_column($recipients, 'user_id');
            $options = [
                'template_type' => $resolvedTemplate['template_type'],
                'subject' => $customSubject !== '' ? $customSubject : $resolvedTemplate['subject'],
                'body' => $customBody !== '' ? $customBody : $resolvedTemplate['body_html'],
            ];
            if ($options['body'] === null || $options['body'] === '') {
                unset($options['body']);
            }

            $results = $emailService->sendEventReminder($event['id'], $organizationId, $recipientIds, $options, $branding);

            jsonResponse([
                'success' => true,
                'message' => "Reminder sent to {$results['sent']} attendees" . ($results['failed'] > 0 ? " ({$results['failed']} failed)" : ''),
                'details' => [
                    'sent' => $results['sent'],
                    'failed' => $results['failed'],
                    'total' => count($recipientIds),
                    'recipient_scope' => 'rsvp_yes',
                    'rsvp_source_event_id' => $rsvpSourceEventId,
                ]
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to send reminder: ' . $e->getMessage()], 500);
        }
        exit;
    }

    // RESEND RSVP confirmations to all attendees with status yes
    if ($action === 'resend-confirmations' && isPost()) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true) ?: [];

        if (empty($input['id'])) {
            jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
        }

        try {
            $targetEventId = (int) $input['id'];
            $event = $db->queryOne(
                "SELECT * FROM events WHERE id = :id AND organization_id = :org_id",
                ['id' => $targetEventId, 'org_id' => $organizationId]
            );
            if (!$event) {
                jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            }

            $rsvpSourceEventId = EventSeriesHelper::getRsvpSourceEventId($db, $targetEventId);
            $rows = $db->query(
                "SELECT r.*, u.id AS member_user_id, u.email, u.first_name, u.last_name, u.password_hash, u.organization_id
                 FROM rsvps r
                 JOIN users u ON u.id = r.user_id
                 WHERE r.event_id = :eid AND r.status = 'yes'
                   AND u.email IS NOT NULL AND TRIM(u.email) != ''",
                ['eid' => $rsvpSourceEventId]
            );

            if (empty($rows)) {
                jsonResponse(['success' => false, 'message' => 'No RSVP yes attendees found for this event.'], 400);
            }

            $org = $db->queryOne(
                "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
                [$organizationId]
            );
            if (!$org || empty($org['smtp_from_email'])) {
                jsonResponse(['success' => false, 'message' => 'Email service not configured. Please configure SMTP in Settings > Email.'], 500);
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
                jsonResponse(['success' => false, 'message' => 'Invalid API key. Please reconfigure your email settings.'], 500);
            }

            $emailConfig = [
                'api_key' => $apiKey,
                'from_email' => $org['smtp_from_email'],
                'from_name' => $org['smtp_from_name'] ?? null,
                'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
            ];
            $portalEmail = new PortalEmailService($emailConfig);

            $sent = 0;
            $failed = 0;
            foreach ($rows as $row) {
                $member = [
                    'id' => (int) $row['member_user_id'],
                    'email' => $row['email'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'password_hash' => $row['password_hash'],
                    'organization_id' => !empty($row['organization_id']) ? (int) $row['organization_id'] : $organizationId,
                ];
                $rsvp = $row;
                unset($rsvp['member_user_id']);

                try {
                    if (empty($member['password_hash'])) {
                        $result = $portalEmail->sendGuestRSVPConfirmation($rsvp, $event, $member, null);
                    } else {
                        $result = $portalEmail->sendRSVPConfirmation($rsvp, $event, $member);
                    }
                    if (!empty($result['success'])) {
                        $sent++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    error_log('resend-confirmations: ' . $member['email'] . ' — ' . $e->getMessage());
                }
                usleep(200000);
            }

            jsonResponse([
                'success' => true,
                'message' => "Confirmation resent to {$sent} attendee(s)" . ($failed > 0 ? " ({$failed} failed)" : ''),
                'details' => [
                    'sent' => $sent,
                    'failed' => $failed,
                    'total' => count($rows),
                ],
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to resend confirmations: ' . $e->getMessage()], 500);
        }
        exit;
    }

    jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    
} catch (Exception $e) {
    // Clear any output that might have been generated
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Ensure we're sending JSON
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['error'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Error $e) {
    // Clear any output that might have been generated
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Ensure we're sending JSON
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['error'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    // Catch any other throwable (PHP 7+)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['error'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
