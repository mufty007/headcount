<?php
/**
 * Edit event — multi-step form
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
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\EventQuestionMergeService;
use Headcount\Services\EventHeadcountPricingService;
use Headcount\Services\PrayerTimesService;
use Headcount\Services\AdminEventRecurrenceService;
use Headcount\Services\RecurringEventService;
use Headcount\Services\EventVisibilityService;
use Headcount\Services\PotluckCategoryService;
use Headcount\Helpers\EventTicketTypesPersistence;
use Headcount\Services\FacilityService;
use Headcount\Services\EventFacilityService;
use Headcount\Services\EventChecklistService;
use Headcount\Services\EventRequestService;

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

$eventId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($eventId <= 0) {
    Utilities::redirect($adminBase . '/index.php?page=events');
    exit;
}

$event = $db->queryOne(
    'SELECT * FROM events WHERE id = :id AND organization_id = :org',
    ['id' => $eventId, 'org' => $organizationId]
);
if (!$event) {
    Utilities::redirect($adminBase . '/index.php?page=events');
    exit;
}

$canManageEvents = AuthMiddleware::can('events.manage');
$fromApprovedRequest = false;
$linkedEventRequest = null;
try {
    $eventRequestService = new EventRequestService();
    if ($eventRequestService->tablesExist()) {
        $fromApprovedRequest = $eventRequestService->userCanCompleteRequestEvent($organizationId, $userId, $eventId);
        $linkedEventRequest = $eventRequestService->findForEvent($organizationId, $eventId);
    }
} catch (\Throwable $e) {
    error_log('event-edit.php event request lookup: ' . $e->getMessage());
}
if (!$canManageEvents && !$fromApprovedRequest) {
    http_response_code(403);
    echo 'Access denied. You can only edit a draft event created from your approved request, or you need the Manage events permission.';
    exit;
}

headcount_decode_html_entities_in_event_row($event);

// Show/save portal visibility whenever the column exists or the loaded row includes it (avoids SHOW COLUMNS false negatives).
$hasEventsVisibilityCol = headcount_events_has_visibility_column($db) || array_key_exists('visibility', $event);

$categories = [];
try {
    $categories = $db->query(
        'SELECT id, name, slug, color FROM categories WHERE organization_id = :org_id AND is_active = 1 ORDER BY sort_order, name',
        ['org_id' => $organizationId]
    );
} catch (\Throwable $e) {
    error_log('event-edit.php: categories query failed: ' . $e->getMessage());
}

$hasEventFacilityCol = false;
$facilityOptions = [];
try {
    $hasEventFacilityCol = headcount_db_has_column($db, 'events', 'facility_id')
        || $db->tableExists('event_facilities');
    if ($hasEventFacilityCol) {
        $facSvc = new FacilityService();
        if ($facSvc->tableExists()) {
            $facilityOptions = $facSvc->listForOrg($organizationId, ['status' => 'active']);
        }
    }
} catch (\Throwable $e) {
    error_log('event-edit.php: facility options failed: ' . $e->getMessage());
    $hasEventFacilityCol = array_key_exists('facility_id', $event);
}

$selectedCatIds = [];
try {
    $ec = $db->query('SELECT category_id FROM event_categories WHERE event_id = :eid', ['eid' => $eventId]);
    foreach ($ec as $row) {
        $selectedCatIds[] = (int) $row['category_id'];
    }
} catch (\Exception $e) {
    $selectedCatIds = [];
}

$preloadQuestions = [];
try {
    $hasDepends = headcount_db_has_column($db, 'event_questions', 'depends_on_question_id');
    $sql = $hasDepends
        ? 'SELECT id, question_text, question_type, is_required, sort_order, depends_on_question_id, depends_on_value FROM event_questions WHERE event_id = :eid ORDER BY sort_order ASC, id ASC'
        : 'SELECT id, question_text, question_type, is_required, sort_order FROM event_questions WHERE event_id = :eid ORDER BY sort_order ASC, id ASC';
    $qs = $db->query($sql, ['eid' => $eventId]);
    foreach ($qs as $q) {
        $opts = [];
        try {
            $opts = $db->query(
                'SELECT id, option_label, sort_order FROM event_question_options WHERE question_id = :qid ORDER BY sort_order ASC, id ASC',
                ['qid' => (int) $q['id']]
            );
        } catch (\Throwable $e) {
        }
        $q['options'] = $opts ?: [];
        if (!$hasDepends) {
            $q['depends_on_question_id'] = null;
            $q['depends_on_value'] = null;
        }
        $preloadQuestions[] = $q;
    }
} catch (\Throwable $e) {
}

$ticketTypesInitial = EventTicketTypesPersistence::loadTicketTypesForEvent($db, $eventId);
$hasPersistedNamedTicketTypesFromDb = false;
foreach ($ticketTypesInitial as $_ttRow) {
    if (trim((string) ($_ttRow['name'] ?? '')) !== '') {
        $hasPersistedNamedTicketTypesFromDb = true;
        break;
    }
}

$headcountTiersInitial = [];
if (!empty($event['headcount_pricing_tiers'])) {
    $rawTiers = $event['headcount_pricing_tiers'];
    if (is_string($rawTiers)) {
        $headcountTiersInitial = json_decode($rawTiers, true) ?: [];
    } elseif (is_array($rawTiers)) {
        $headcountTiersInitial = $rawTiers;
    }
}

$isRecurringInstance = false;
try {
    if (headcount_db_has_column($db, 'events', 'parent_event_id') && !empty($event['parent_event_id'])) {
        $isRecurringInstance = true;
    }
    if (headcount_db_has_column($db, 'events', 'is_recurring_instance') && !empty($event['is_recurring_instance'])) {
        $isRecurringInstance = true;
    }
} catch (\Throwable $e) {
}

$recurringRow = null;
if (!$isRecurringInstance) {
    try {
        $recurringRow = $db->queryOne(
            'SELECT * FROM recurring_events WHERE parent_event_id = :id LIMIT 1',
            ['id' => $eventId]
        );
    } catch (\Throwable $e) {
    }
}

$customDatesText = '';
if ($recurringRow && !empty($recurringRow['custom_dates'])) {
    $dec = json_decode($recurringRow['custom_dates'], true);
    if (is_array($dec)) {
        $customDatesText = implode("\n", $dec);
    }
}
$recurrenceDaysInitial = [];
if ($recurringRow && !empty($recurringRow['days_of_week'])) {
    $recurrenceDaysInitial = array_map('intval', array_map('trim', explode(',', $recurringRow['days_of_week'])));
}

$sessionRegInitial = strtolower(trim((string) ($event['session_registration_mode'] ?? 'independent')));
if (!in_array($sessionRegInitial, ['independent', 'choose_one', 'all_sessions'], true)) {
    $sessionRegInitial = 'independent';
}

$checklistSvc = new EventChecklistService($db);
$checklistRoles = $checklistSvc->tablesExist() ? $checklistSvc->listRoles($organizationId) : [];
$checklistStaff = $checklistSvc->tablesExist() ? $checklistSvc->listStaffForEventChecklist($organizationId, $eventId) : [];
$checklistStorageEventId = EventChecklistService::storageEventId($event);
$checklistLeadershipSelected = [];
if ($checklistSvc->tablesExist()) {
    foreach ($checklistSvc->getLeadership($checklistStorageEventId) as $leadRow) {
        $checklistLeadershipSelected[(int) $leadRow['role_id']] = (int) $leadRow['user_id'];
    }
}

$errors = [];
$formData = [
    'title' => $event['title'] ?? '',
    'description' => $event['description'] ?? '',
    'event_date' => substr((string) ($event['event_date'] ?? ''), 0, 10),
    'start_time' => $event['start_time'] ? substr((string) $event['start_time'], 0, 5) : '',
    'end_time' => $event['end_time'] ? substr((string) $event['end_time'], 0, 5) : '',
    'location' => $event['location'] ?? '',
    'facility_id' => $hasEventFacilityCol && !empty($event['facility_id']) ? (int) $event['facility_id'] : '',
    'facility_ids' => (new EventFacilityService($db))->idsForEvent($eventId),
    'is_virtual' => !empty($event['is_virtual']),
    'extra_details' => $event['extra_details'] ?? '',
    'capacity' => $event['capacity'] ?? '',
    'ticket_price' => isset($event['ticket_price']) ? number_format((float) $event['ticket_price'], 2, '.', '') : '0.00',
    'pricing_model' => ($event['pricing_model'] ?? EventHeadcountPricingService::MODEL_PER_PERSON) === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
        ? EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
        : EventHeadcountPricingService::MODEL_PER_PERSON,
    'registration_required' => !empty($event['registration_required']),
    'registration_deadline' => '',
    'min_age' => isset($event['min_age']) && $event['min_age'] !== null && $event['min_age'] !== '' ? (int) $event['min_age'] : '',
    'max_age' => isset($event['max_age']) && $event['max_age'] !== null && $event['max_age'] !== '' ? (int) $event['max_age'] : '',
    'gender_restriction' => $event['gender_restriction'] ?? 'none',
    'enforce_restrictions_at_checkin' => !empty($event['enforce_restrictions_at_checkin']),
    'allow_guest_rsvp' => !empty($event['allow_guest_rsvp']),
    'allow_bring_guests' => !empty($event['allow_bring_guests']),
    'is_potluck' => !empty($event['is_potluck']),
    'collect_feedback' => !empty($event['collect_feedback']),
    'potluck_show_bringing_prompt' => !array_key_exists('potluck_show_bringing_prompt', $event) || !empty($event['potluck_show_bringing_prompt']),
    'potluck_allowed_slugs' => PotluckCategoryService::adminSelectedSlugsForPotluckForm($event),
    'status' => $event['status'] ?? 'draft',
    'visibility' => EventVisibilityService::fromEventRow($event),
    'checkin_window_start' => $event['checkin_window_start'] ? substr((string) $event['checkin_window_start'], 0, 5) : '',
    'checkin_window_end' => $event['checkin_window_end'] ? substr((string) $event['checkin_window_end'], 0, 5) : '',
    'category' => $event['category'] ?? '',
    'categories' => array_map('strval', $selectedCatIds),
    'is_recurring' => (bool) $recurringRow,
    'recurrence_type' => $recurringRow['recurrence_type'] ?? 'weekly',
    'recurrence_interval' => isset($recurringRow['interval']) ? max(1, (int) $recurringRow['interval']) : 1,
    'recurrence_days' => $recurrenceDaysInitial,
    'recurrence_week_of_month' => isset($recurringRow['week_of_month']) && $recurringRow['week_of_month'] !== null
        ? (string) (int) $recurringRow['week_of_month']
        : '',
    'recurrence_end_type' => $recurringRow['end_type'] ?? 'never',
    'recurrence_end_after_count' => isset($recurringRow['end_after_count']) && $recurringRow['end_after_count'] !== null
        ? (string) (int) $recurringRow['end_after_count']
        : '',
    'recurrence_end_date' => !empty($recurringRow['end_date']) ? substr((string) $recurringRow['end_date'], 0, 10) : '',
    'custom_session_dates_text' => $customDatesText,
    'session_registration_mode' => $sessionRegInitial,
    'target_attendance' => isset($event['target_attendance']) && $event['target_attendance'] !== null && $event['target_attendance'] !== ''
        ? (int) $event['target_attendance'] : '',
    'budget' => isset($event['budget']) && $event['budget'] !== null && $event['budget'] !== ''
        ? number_format((float) $event['budget'], 2, '.', '') : '',
    'checklist_leadership' => $checklistLeadershipSelected,
];

$rdRaw = $event['registration_deadline'] ?? '';
if ($rdRaw) {
    $formData['registration_deadline'] = str_replace(' ', 'T', substr((string) $rdRaw, 0, 16));
}

if ($hasPersistedNamedTicketTypesFromDb && $formData['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER) {
    $formData['pricing_model'] = EventHeadcountPricingService::MODEL_PER_PERSON;
}

$startTimeMode = 'clock';
$prayerNameField = '';
$prayerOffsetField = 0;
if (isPost()) {
    $startTimeMode = post('start_time_mode', 'clock') === 'after_prayer' ? 'after_prayer' : 'clock';
    $prayerNameField = post('prayer_name', '');
    $prayerOffsetField = (int) post('prayer_offset', 0);
} elseif (!empty($event['prayer_name'])) {
    $startTimeMode = 'after_prayer';
    $prayerNameField = (string) $event['prayer_name'];
    $prayerOffsetField = (int) ($event['prayer_offset'] ?? 0);
}

if (isPost()) {
    CsrfMiddleware::verify();

    $formData = [
        'title' => sanitizePlainText(post('title')),
        'description' => post('description'),
        'event_date' => post('event_date'),
        'start_time' => post('start_time'),
        'end_time' => post('end_time'),
        'location' => sanitizePlainText(post('location')),
        'facility_ids' => headcount_event_facility_ids_from_post($db, (int) $organizationId),
        'facility_id' => headcount_event_facility_id_from_post($db, (int) $organizationId),
        'is_virtual' => (bool) post('is_virtual'),
        'extra_details' => post('extra_details') ?: '',
        'capacity' => post('capacity') !== '' ? (int) post('capacity') : null,
        'ticket_price' => post('ticket_price') !== '' ? (float) post('ticket_price') : 0.0,
        'pricing_model' => post('pricing_model') === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
            ? EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
            : EventHeadcountPricingService::MODEL_PER_PERSON,
        'registration_required' => post('registration_required') ? 1 : 0,
        'registration_deadline' => post('registration_deadline') ?: null,
        'min_age' => post('min_age') !== '' && post('min_age') !== null ? (int) post('min_age') : null,
        'max_age' => post('max_age') !== '' && post('max_age') !== null ? (int) post('max_age') : null,
        'gender_restriction' => post('gender_restriction', 'none'),
        'enforce_restrictions_at_checkin' => post('enforce_restrictions_at_checkin') ? 1 : 0,
        'allow_guest_rsvp' => post('allow_guest_rsvp') ? 1 : 0,
        'allow_bring_guests' => post('allow_bring_guests') ? 1 : 0,
        'is_potluck' => post('is_potluck') ? 1 : 0,
        'collect_feedback' => post('collect_feedback') ? 1 : 0,
        'potluck_show_bringing_prompt' => post('is_potluck') ? (post('potluck_show_bringing_prompt') ? 1 : 0) : 1,
        'potluck_allowed_slugs' => isset($_POST['potluck_allowed_slugs']) && is_array($_POST['potluck_allowed_slugs'])
            ? array_values(array_filter(array_map('strval', $_POST['potluck_allowed_slugs'])))
            : PotluckCategoryService::orderedSlugs(),
        'status' => post('status', 'draft'),
        'visibility' => headcount_post_visibility('visibility', EventVisibilityService::fromEventRow($event)),
        'checkin_window_start' => post('checkin_window_start') ?: null,
        'checkin_window_end' => post('checkin_window_end') ?: null,
        'categories' => isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : [],
    ];

    $recurrenceInput = AdminEventRecurrenceService::inputFromPost();
    $formData['is_recurring'] = requestBoolFromInput($recurrenceInput, 'is_recurring', false);
    $formData['recurrence_type'] = $recurrenceInput['recurrence_type'] ?? 'weekly';
    $formData['recurrence_interval'] = max(1, (int) ($recurrenceInput['recurrence_interval'] ?? 1));
    $formData['recurrence_days'] = isset($recurrenceInput['recurrence_days']) && is_array($recurrenceInput['recurrence_days'])
        ? array_values(array_map('intval', $recurrenceInput['recurrence_days']))
        : [];
    $formData['recurrence_week_of_month'] = $recurrenceInput['recurrence_week_of_month'] ?? '';
    $formData['recurrence_end_type'] = $recurrenceInput['recurrence_end_type'] ?? 'never';
    $formData['recurrence_end_after_count'] = $recurrenceInput['recurrence_end_after_count'] ?? '';
    $formData['recurrence_end_date'] = $recurrenceInput['recurrence_end_date'] ?? '';
    $formData['custom_session_dates_text'] = post('custom_session_dates_text') ?: '';
    $srPost = strtolower(trim((string) post('session_registration_mode', 'independent')));
    $formData['session_registration_mode'] = in_array($srPost, ['independent', 'choose_one', 'all_sessions'], true)
        ? $srPost
        : 'independent';
    $formData['target_attendance'] = post('target_attendance') !== '' ? (int) post('target_attendance') : null;
    $formData['budget'] = post('budget') !== '' ? (float) post('budget') : null;
    $leadershipPost = $_POST['checklist_leadership'] ?? [];
    $formData['checklist_leadership'] = is_array($leadershipPost) ? $leadershipPost : [];

    if ($formData['title'] === '') {
        $errors[] = 'Title is required.';
    }
    if ($formData['event_date'] === '') {
        $errors[] = 'Event date is required.';
    }
    if ($formData['location'] === '') {
        $errors[] = 'Location is required.';
    }
    if ($checklistSvc->tablesExist()) {
        $overallLeadRoleId = null;
        foreach ($checklistRoles as $cr) {
            if (($cr['role_key'] ?? '') === 'overall_lead') {
                $overallLeadRoleId = (int) $cr['id'];
                break;
            }
        }
        if ($overallLeadRoleId === null || empty($formData['checklist_leadership'][$overallLeadRoleId])) {
            $errors[] = 'Overall Event Lead is required on the Team & leadership step.';
        }
    }
    if ($hasEventFacilityCol && isset($_POST['facility_ids']) && headcount_resolve_event_facility_ids($db, (int) $organizationId, $_POST['facility_ids']) === false) {
        $errors[] = 'One or more selected facilities are not valid.';
        $formData['facility_ids'] = [];
        $formData['facility_id'] = null;
    }
    $facilityTimeErr = headcount_validate_event_facility_times(
        $formData['facility_ids'] ?? [],
        (string) ($formData['start_time'] ?? ''),
        (string) ($formData['end_time'] ?? '')
    );
    if ($facilityTimeErr !== null) {
        $errors[] = $facilityTimeErr;
    }
    if (($formData['status'] ?? '') === 'published' && !empty($formData['facility_ids'])) {
        $conflicts = (new EventFacilityService($db))->conflictMessages(
            (int) $organizationId,
            $formData['facility_ids'],
            (string) $formData['event_date'],
            (string) $formData['start_time'],
            (string) $formData['end_time'],
            $eventId
        );
        foreach ($conflicts as $c) {
            $errors[] = $c;
        }
    }
    $gr = strtolower(trim((string) ($formData['gender_restriction'] ?? 'none')));
    if (!in_array($gr, ['none', 'male', 'female', 'other'], true)) {
        $gr = 'none';
    }
    $formData['gender_restriction'] = $gr;
    $formData['visibility'] = EventVisibilityService::normalize((string) ($formData['visibility'] ?? 'public'));
    if ($formData['min_age'] !== null && $formData['min_age'] < 0) {
        $errors[] = 'Minimum age cannot be negative.';
    }
    if ($formData['max_age'] !== null && $formData['max_age'] < 0) {
        $errors[] = 'Maximum age cannot be negative.';
    }
    if ($formData['min_age'] !== null && $formData['max_age'] !== null && $formData['min_age'] > $formData['max_age']) {
        $errors[] = 'Minimum age cannot be greater than maximum age.';
    }

    $tierSvc = new EventHeadcountPricingService();
    $normPosted = $tierSvc->normalizeTiersFromInput(post('headcount_pricing_tiers_json'));
    if ($normPosted['error'] !== null) {
        $errors[] = $normPosted['error'];
    } elseif ($formData['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER) {
        $v = $tierSvc->validateTiersForSave($normPosted['tiers']);
        if ($v !== null) {
            $errors[] = $v;
        }
    }

    if (!$isRecurringInstance && requestBoolFromInput($recurrenceInput, 'is_recurring', false)) {
        $rtype = strtolower(trim((string) ($recurrenceInput['recurrence_type'] ?? 'weekly')));
        if ($rtype === 'weekly' && !recurrenceDaysProvided($recurrenceInput)) {
            $errors[] = 'Select at least one weekday for weekly recurring events (Sunday counts as a weekday).';
        }
        if ($rtype === 'custom') {
            if (!$db->hasColumn('recurring_events', 'custom_dates')) {
                $errors[] = 'Specific dates require database migration 037 (recurring_events.custom_dates).';
            } elseif (!empty($recurrenceInput['custom_session_dates_text_error'])) {
                $errors[] = (string) $recurrenceInput['custom_session_dates_text_error'];
            } else {
                $encRes = RecurringEventService::encodeCustomDatesFromInputResult($recurrenceInput, $formData['event_date']);
                if (!empty($encRes['error'])) {
                    $errors[] = $encRes['error'];
                } elseif (($encRes['json'] ?? null) === null) {
                    $errors[] = 'For “Specific dates”, add at least one additional session date (besides the main event date).';
                }
            }
        }
    }

    $bannerImagePath = $event['banner_image'] ?? null;
    if (!empty($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
        try {
            $uploadConfig = $config['uploads'] ?? [];
            $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $uploadConfig['max_size'] = 5242880;
            if (empty($uploadConfig['upload_path'])) {
                $uploadConfig['upload_path'] = __DIR__ . '/../../uploads/';
            }
            $uploadConfig['upload_path'] = rtrim(realpath($uploadConfig['upload_path']) ?: $uploadConfig['upload_path'], '/\\') . '/';
            $fileUpload = new FileUpload($uploadConfig);
            $uploadResult = $fileUpload->upload($_FILES['banner_image'], 'event-banners');
            $bannerImagePath = 'event-banners/' . $uploadResult['filename'];
            $full = $uploadConfig['upload_path'] . str_replace('/', DIRECTORY_SEPARATOR, $bannerImagePath);
            if (!file_exists($full)) {
                $bannerImagePath = $event['banner_image'] ?? null;
            } elseif (!empty($event['banner_image']) && $event['banner_image'] !== $bannerImagePath) {
                $old = $uploadConfig['upload_path'] . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($event['banner_image'], '/\\'));
                if (file_exists($old) && is_file($old)) {
                    @unlink($old);
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'Banner upload failed: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $postedTtCheck = EventTicketTypesPersistence::parseTicketTypesFromRequest($_POST);
        foreach ($postedTtCheck as $r) {
            if (trim((string) ($r['name'] ?? '')) !== '') {
                $formData['pricing_model'] = EventHeadcountPricingService::MODEL_PER_PERSON;
                break;
            }
        }

        $legacyCategory = !empty($formData['categories'][0])
            ? $db->queryOne('SELECT name FROM categories WHERE id = :id AND organization_id = :org', [
                'id' => (int) $formData['categories'][0],
                'org' => $organizationId,
            ])
            : null;
        $legacyName = $legacyCategory['name'] ?? ($formData['category'] ?? 'other');

        $update = [
            'title' => $formData['title'],
            'description' => $formData['description'] ?: null,
            'event_date' => $formData['event_date'],
            'start_time' => $formData['start_time'] ?: null,
            'end_time' => $formData['end_time'] ?: null,
            'location' => $formData['location'],
            'capacity' => $formData['capacity'],
            'ticket_price' => $formData['ticket_price'],
            'registration_required' => $formData['registration_required'],
            'registration_deadline' => $formData['registration_deadline'],
            'status' => $formData['status'],
            'checkin_window_start' => $formData['checkin_window_start'],
            'checkin_window_end' => $formData['checkin_window_end'],
            'banner_image' => $bannerImagePath,
            'category' => $legacyName,
        ];
        if ($hasEventsVisibilityCol) {
            $update['visibility'] = $formData['visibility'];
        }

        try {
            $cols = $db->query('SHOW COLUMNS FROM events');
            $colNames = array_column($cols, 'Field');
            if (in_array('is_virtual', $colNames, true)) {
                $update['is_virtual'] = $formData['is_virtual'] ? 1 : 0;
            }
            if (in_array('extra_details', $colNames, true)) {
                $update['extra_details'] = $formData['extra_details'] ?: null;
            }
            if (in_array('allow_guest_rsvp', $colNames, true)) {
                $update['allow_guest_rsvp'] = $formData['allow_guest_rsvp'];
            }
            if (in_array('allow_bring_guests', $colNames, true)) {
                $update['allow_bring_guests'] = $formData['allow_bring_guests'];
            }
            if (in_array('is_potluck', $colNames, true)) {
                $update['is_potluck'] = $formData['is_potluck'];
            }
            if (in_array('collect_feedback', $colNames, true)) {
                $update['collect_feedback'] = !empty($formData['collect_feedback']) ? 1 : 0;
            }
            if (in_array('potluck_allowed_slugs', $colNames, true)) {
                $slugsPost = isset($formData['potluck_allowed_slugs']) && is_array($formData['potluck_allowed_slugs'])
                    ? $formData['potluck_allowed_slugs']
                    : [];
                $update['potluck_allowed_slugs'] = PotluckCategoryService::potluckAllowedSlugsJsonForStorage(
                    (bool) $formData['is_potluck'],
                    $slugsPost
                );
            }
            if (in_array('potluck_show_bringing_prompt', $colNames, true)) {
                $update['potluck_show_bringing_prompt'] = !empty($formData['potluck_show_bringing_prompt']) ? 1 : 0;
            }
            if (in_array('pricing_model', $colNames, true)) {
                $update['pricing_model'] = $formData['pricing_model'];
            }
            if (in_array('headcount_pricing_tiers', $colNames, true)) {
                $update['headcount_pricing_tiers'] = $formData['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
                    ? json_encode($normPosted['tiers'])
                    : null;
            }
            if (in_array('min_age', $colNames, true)) {
                $update['min_age'] = $formData['min_age'];
            }
            if (in_array('max_age', $colNames, true)) {
                $update['max_age'] = $formData['max_age'];
            }
            if (in_array('gender_restriction', $colNames, true)) {
                $update['gender_restriction'] = $formData['gender_restriction'];
            }
            if (in_array('enforce_restrictions_at_checkin', $colNames, true)) {
                $update['enforce_restrictions_at_checkin'] = !empty($formData['enforce_restrictions_at_checkin']) ? 1 : 0;
            }
            if (in_array('facility_id', $colNames, true)) {
                $update['facility_id'] = !empty($formData['facility_ids'][0]) ? (int) $formData['facility_ids'][0] : null;
            }
            if (!$isRecurringInstance && in_array('session_registration_mode', $colNames, true)) {
                $update['session_registration_mode'] = $formData['session_registration_mode'];
            }
            if (in_array('target_attendance', $colNames, true)) {
                $update['target_attendance'] = $formData['target_attendance'];
            }
            if (in_array('budget', $colNames, true)) {
                $update['budget'] = $formData['budget'];
            }
            if (in_array('prayer_name', $colNames, true) && in_array('prayer_offset', $colNames, true)) {
                $mode = post('start_time_mode', 'clock');
                if ($mode === 'after_prayer') {
                    $orgRow = $db->queryOne('SELECT * FROM organizations WHERE id = ?', [$organizationId]);
                    $pn = post('prayer_name');
                    $off = (int) post('prayer_offset', 0);
                    $city = trim((string) ($orgRow['city'] ?? ''));
                    $country = trim((string) ($orgRow['country'] ?? ''));
                    if ($pn && $city !== '' && $country !== '' && !empty($formData['event_date'])) {
                        $ct = PrayerTimesService::timeAfterPrayer($formData['event_date'], $city, $country, $pn, $off);
                        if ($ct !== null) {
                            $update['start_time'] = $ct;
                            $update['prayer_name'] = $pn;
                            $update['prayer_offset'] = $off;
                        }
                    }
                } else {
                    $update['prayer_name'] = null;
                    $update['prayer_offset'] = 0;
                }
            }
        } catch (\Exception $e) {
            /* ignore */
        }

        try {
            $db->beginTransaction();
            $oldEventDate = substr((string) ($event['event_date'] ?? ''), 0, 10);
            $db->update('events', $eventId, $update);
            (new EventFacilityService($db))->syncEvent((int) $eventId, (int) $organizationId, $formData['facility_ids'] ?? []);
            try {
                $db->execute('DELETE FROM event_categories WHERE event_id = :eid', ['eid' => $eventId]);
            } catch (\Exception $e) {
                /* table may not exist */
            }
            foreach ($formData['categories'] as $catVal) {
                $targetCatId = null;
                if (is_numeric($catVal)) {
                    $targetCatId = (int) $catVal;
                } else {
                    $existing = $db->queryOne(
                        'SELECT id FROM categories WHERE name = :name AND organization_id = :org_id',
                        ['name' => $catVal, 'org_id' => $organizationId]
                    );
                    if ($existing) {
                        $targetCatId = (int) $existing['id'];
                    }
                }
                if ($targetCatId) {
                    try {
                        $db->insert('event_categories', [
                            'event_id' => $eventId,
                            'category_id' => $targetCatId,
                        ]);
                    } catch (\Exception $e) {
                        /* ignore duplicate */
                    }
                }
            }
            EventTicketTypesPersistence::replaceTicketTypesForEvent(
                $db,
                $eventId,
                EventTicketTypesPersistence::parseTicketTypesFromRequest($_POST)
            );
            $sync = AdminEventRecurrenceService::sync(
                $db,
                $organizationId,
                $eventId,
                $formData['event_date'],
                $recurrenceInput,
                $isRecurringInstance
            );
            if (!$sync['ok']) {
                $db->rollback();
                $errors[] = $sync['error'] ?? 'Recurring settings could not be saved.';
            } else {
                if ($hasEventFacilityCol && !$isRecurringInstance && $db->hasColumn('events', 'parent_event_id')) {
                    $childIds = $db->query(
                        'SELECT id FROM events WHERE parent_event_id = :pid AND organization_id = :org',
                        ['pid' => $eventId, 'org' => $organizationId]
                    ) ?: [];
                    $linkSvc = new EventFacilityService($db);
                    foreach ($childIds as $child) {
                        $linkSvc->syncEvent((int) $child['id'], (int) $organizationId, $formData['facility_ids'] ?? []);
                    }
                }
                $db->commit();
                $questionsInput = $_POST['questions'] ?? [];
                if (!is_array($questionsInput)) {
                    $questionsInput = [];
                }
                (new EventQuestionMergeService($db))->mergeForEvent($eventId, $questionsInput);
                if ($oldEventDate !== substr((string) ($formData['event_date'] ?? ''), 0, 10)) {
                    try {
                        (new EventChecklistService($db))->recalculateDueDates($eventId, $organizationId);
                    } catch (\Throwable $e) {
                        error_log('Checklist due date recalc: ' . $e->getMessage());
                    }
                }
                if ($checklistSvc->tablesExist()) {
                    try {
                        $leadResult = $checklistSvc->setLeadership(
                            $checklistStorageEventId,
                            $organizationId,
                            $formData['checklist_leadership']
                        );
                        if (!$leadResult['ok']) {
                            error_log('Checklist leadership save: ' . ($leadResult['error'] ?? 'unknown'));
                        }
                    } catch (\Throwable $e) {
                        error_log('Checklist leadership save: ' . $e->getMessage());
                    }
                }
                $gen = (int) ($sync['generated'] ?? 0);
                $msg = 'Event updated successfully.';
                if ($gen > 0) {
                    $msg .= ' ' . $gen . ' new recurring session(s) were generated.';
                }
                setFlash('success', $msg);
                Utilities::redirect($adminBase . '/index.php?page=event-details&id=' . $eventId);
            }
        } catch (\Exception $e) {
            try {
                if ($db->getConnection()->inTransaction()) {
                    $db->rollback();
                }
            } catch (\Throwable $t) {
                /* ignore */
            }
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $ticketTypesInitial = EventTicketTypesPersistence::parseTicketTypesFromRequest($_POST);
    }
}

$ticketTypesRowsForTemplate = $ticketTypesInitial;
if ($ticketTypesRowsForTemplate === []) {
    $ticketTypesRowsForTemplate = [[
        'name' => '',
        'price' => '',
        'quantity_limit' => '',
        'sale_starts_at' => '',
        'sale_ends_at' => '',
        'package_group' => '',
    ]];
}

$userData = null;
try {
    $userData = $db->queryOne('SELECT first_name, last_name, email, role FROM users WHERE id = :id', ['id' => $userId]);
} catch (\Throwable $e) {
    error_log('event-edit.php: user query failed: ' . $e->getMessage());
}
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

$pageTitle = 'Edit Event';
$currentPage = 'events';
$adminMainFullWidth = true;
$requiresQuillEditor = true;
$requiresEventWizard = true;
require __DIR__ . '/includes/header.php';

$flash = getFlash();
?>

<div class="animate-fade-in admin-event-wizard w-full min-w-0" style="width:100%;max-width:100%">
    <?php
    $pageHeaderTitle = 'Edit Event';
    $pageHeaderSubtitle = 'Update the details for ' . e($event['title']) . '.';
    $pageHeaderBreadcrumb = [
        ['label' => 'Events', 'url' => $adminBase . '/index.php?page=events'],
        ['label' => $event['title'], 'url' => $adminBase . '/index.php?page=event-details&id=' . $eventId],
        ['label' => 'Edit'],
    ];
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if (!empty($fromApprovedRequest) && ($event['status'] ?? '') === 'draft'): ?>
        <div class="ta-alert ta-alert-info mb-6">
            <p class="font-medium">This draft was created from your approved event request. Complete the remaining details and publish when it is ready.</p>
        </div>
    <?php elseif (!empty($linkedEventRequest) && ($linkedEventRequest['status'] ?? '') === 'approved' && ($event['status'] ?? '') === 'draft'): ?>
        <div class="ta-alert ta-alert-info mb-6">
            <p class="font-medium">This draft was created from an approved event request. Complete the remaining details and publish when it is ready.</p>
        </div>
    <?php endif; ?>

    <?php if ($flash && ($flash['type'] ?? '') === 'success'): ?>
        <div class="ta-alert ta-alert-success mb-6">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="ta-alert ta-alert-error mb-6 flex-col items-start">
            <p class="font-semibold mb-1">Please fix the following errors:</p>
            <ul class="list-disc list-inside text-sm space-y-0.5">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Step Progress -->
    <div class="multi-step-progress">
        <div class="step-item active" id="step-item-1" data-wizard-step="1" role="button" tabindex="0">
            <div class="step-circle">1</div>
            <span class="step-label">Basics</span>
        </div>
        <div class="step-item" id="step-item-2" data-wizard-step="2" role="button" tabindex="0">
            <div class="step-circle">2</div>
            <span class="step-label">Schedule</span>
        </div>
        <div class="step-item" id="step-item-3" data-wizard-step="3" role="button" tabindex="0">
            <div class="step-circle">3</div>
            <span class="step-label">Settings</span>
        </div>
        <div class="step-item" id="step-item-4" data-wizard-step="4" role="button" tabindex="0">
            <div class="step-circle">4</div>
            <span class="step-label">RSVP questions</span>
        </div>
        <div class="step-item" id="step-item-5" data-wizard-step="5" role="button" tabindex="0">
            <div class="step-circle">5</div>
            <span class="step-label">Team</span>
        </div>
        <div class="step-item" id="step-item-6" data-wizard-step="6" role="button" tabindex="0">
            <div class="step-circle">6</div>
            <span class="step-label">Review</span>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="event-edit-form">
        <input type="hidden" name="csrf_token" value="<?= e(CsrfMiddleware::getToken()) ?>">

        <!-- Step 1: Basics -->
        <div class="step-panel active" id="panel-1">
            <?php ob_start(); ?>
            <div class="mb-4">
                <label class="form-label" for="title">Event Title <span class="text-red-500">*</span></label>
                <input type="text" id="title" name="title" required value="<?= e($formData['title']) ?>"
                       class="ta-input w-full"
                       placeholder="e.g. Friday Jumu'ah">
            </div>

            <div class="mb-4">
                <label class="form-label" for="description">Description</label>
                <textarea id="description" name="description" rows="4"
                          class="wysiwyg-editor w-full border border-gray-100 rounded-xl px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition-all dark:border-gray-800"
                          placeholder="Event description..."><?= headcount_wysiwyg_textarea_body($formData['description'] ?? '') ?></textarea>
            </div>

            <?php if ($hasEventsVisibilityCol): ?>
            <?php $visVal = $formData['visibility'] ?? 'public'; ?>
            <div class="mb-6 p-4 rounded-xl border border-gray-200 bg-gray-50/80 dark:bg-gray-800 dark:border-gray-700">
                <label class="form-label">Who can see this event (when published)</label>
                <p class="form-hint mb-3">Choose <strong>Public</strong> so members can see the event and RSVP in the portal. Internal events never appear there.</p>
                <input type="hidden" name="visibility" id="headcount-event-visibility-post" value="<?= e($visVal) ?>">
                <div class="space-y-2" role="radiogroup" aria-label="Who can see this event in the member portal">
                    <label class="flex items-start gap-2 cursor-pointer text-sm">
                        <input type="radio" name="visibility_ui" value="public" class="mt-0.5 headcount-visibility-ui" <?= $visVal === 'public' ? 'checked' : '' ?>>
                        <span><span class="font-medium text-gray-800 dark:text-gray-100">Public</span><span class="block text-xs text-gray-500 dark:text-gray-400">Listed for members and public calendar (when published).</span></span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer text-sm">
                        <input type="radio" name="visibility_ui" value="internal" class="mt-0.5 headcount-visibility-ui" <?= $visVal === 'internal' ? 'checked' : '' ?>>
                        <span><span class="font-medium text-gray-800 dark:text-gray-100">Internal (staff only)</span><span class="block text-xs text-gray-500 dark:text-gray-400">Admins and coordinators only — not shown in the member portal.</span></span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer text-sm">
                        <input type="radio" name="visibility_ui" value="invite_only" class="mt-0.5 headcount-visibility-ui" <?= $visVal === 'invite_only' ? 'checked' : '' ?>>
                        <span><span class="font-medium text-gray-800 dark:text-gray-100">Invite-only</span><span class="block text-xs text-gray-500 dark:text-gray-400">Only invited members see it in the portal and can RSVP.</span></span>
                    </label>
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <label class="form-label">Categories</label>
                <div class="mt-2 flex flex-wrap gap-2">
                    <?php foreach ($categories as $cat): ?>
                        <label class="inline-flex items-center gap-2 border border-gray-200 rounded-xl px-3 py-2 text-sm cursor-pointer hover:border-brand-400 hover:bg-brand-50 transition-all dark:border-gray-700">
                            <input type="checkbox" name="categories[]" value="<?= (int) $cat['id'] ?>"
                                <?= in_array((int) $cat['id'], $selectedCatIds, true) ? 'checked' : '' ?>>
                            <?php if (!empty($cat['color'])): ?>
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: <?= e($cat['color']) ?>"></span>
                            <?php endif; ?>
                            <?= e($cat['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="banner_image">Banner Image</label>
                <input type="file" id="banner_image" name="banner_image" accept="image/jpeg,image/png,image/gif,image/webp"
                       class="w-full text-sm border border-gray-200 rounded-xl px-4 py-2.5 file:mr-4 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 dark:border-gray-700">
                <?php if (!empty($event['banner_image'])): ?>
                    <p class="form-hint">Current banner will be kept unless you choose a new image.</p>
                <?php endif; ?>
            </div>

            <div class="mb-4">
                <label class="form-label" for="extra_details">Extra Details</label>
                <textarea id="extra_details" name="extra_details" rows="2"
                          class="ta-input w-full"
                          placeholder="Internal notes or extra details..."><?= e($formData['extra_details']) ?></textarea>
                <p class="form-hint">Additional info shown on the event details page for admins only.</p>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Basic Information';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-primary" data-goto-step="2" onclick="if(window.showStep){window.showStep(2);}return false;">
                    Next: Schedule
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $eventId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 2: Schedule -->
        <div class="step-panel" id="panel-2">
            <?php ob_start(); ?>
            <div class="mb-4 p-4 rounded-xl border border-brand-100 bg-brand-50/50">
                <p class="text-sm font-semibold text-gray-900 mb-1 dark:text-white">Start time mode</p>
                <p class="text-xs text-gray-600 mb-3 dark:text-gray-300">Prayer-based start uses city &amp; country from <a href="<?= e($adminBase . '/index.php?page=settings') ?>" class="text-brand-600 underline hover:text-brand-800">Settings</a> and the <a href="https://aladhan.com/prayer-times-api" target="_blank" rel="noopener noreferrer" class="text-brand-600 underline">Aladhan API</a>.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="form-label" for="start_time_mode">Mode</label>
                        <select name="start_time_mode" id="start_time_mode"
                                class="ta-input w-full">
                            <option value="clock" <?= $startTimeMode === 'clock' ? 'selected' : '' ?>>Fixed clock time</option>
                            <option value="after_prayer" <?= $startTimeMode === 'after_prayer' ? 'selected' : '' ?>>Minutes after a prayer</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="prayer_name">Prayer</label>
                        <select name="prayer_name" id="prayer_name"
                                class="ta-input w-full">
                            <option value="">—</option>
                            <option value="Fajr" <?= $prayerNameField === 'Fajr' ? 'selected' : '' ?>>Fajr</option>
                            <option value="Dhuhr" <?= $prayerNameField === 'Dhuhr' ? 'selected' : '' ?>>Dhuhr</option>
                            <option value="Asr" <?= $prayerNameField === 'Asr' ? 'selected' : '' ?>>Asr</option>
                            <option value="Maghrib" <?= $prayerNameField === 'Maghrib' ? 'selected' : '' ?>>Maghrib</option>
                            <option value="Isha" <?= $prayerNameField === 'Isha' ? 'selected' : '' ?>>Isha</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="prayer_offset">Minutes after</label>
                        <input type="number" name="prayer_offset" id="prayer_offset" min="0" max="600" value="<?= e((string) $prayerOffsetField) ?>"
                               class="ta-input w-full">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="form-label" for="event_date">Event Date <span class="text-red-500">*</span></label>
                    <input type="date" id="event_date" name="event_date" required value="<?= e($formData['event_date']) ?>"
                           class="ta-input w-full">
                </div>
                <div>
                    <label class="form-label" for="start_time">Start Time</label>
                    <input type="time" id="start_time" name="start_time" value="<?= e($formData['start_time']) ?>"
                           class="ta-input w-full">
                </div>
                <div>
                    <label class="form-label" for="end_time">End Time</label>
                    <input type="time" id="end_time" name="end_time" value="<?= e($formData['end_time']) ?>"
                           class="ta-input w-full">
                </div>
            </div>

            <label class="form-toggle mb-4 cursor-pointer">
                <input type="checkbox" name="is_virtual" value="1" <?= !empty($formData['is_virtual']) ? 'checked' : '' ?>>
                <div>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Virtual Event</span>
                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Use a Zoom or Google Meet link as the location</p>
                </div>
            </label>

            <div class="mb-4">
                <label class="form-label" for="location">Location <span class="text-red-500">*</span></label>
                <input type="text" id="location" name="location" required value="<?= e($formData['location']) ?>"
                       class="ta-input w-full"
                       placeholder="Venue name, address, or meeting link">
            </div>

            <?php if ($hasEventFacilityCol && !empty($facilityOptions)):
                $selectedFacilityIds = $formData['facility_ids'] ?? [];
                require __DIR__ . '/includes/facility-multiselect.php';
            endif; ?>

            <?php if ($isRecurringInstance): ?>
            <div class="mb-4 p-4 rounded-xl border border-amber-200 bg-amber-50/80 text-sm text-amber-950">
                <?php if (!empty($event['parent_event_id'])): ?>
                    This session is part of a recurring series.
                    <a href="<?= e($adminBase . '/index.php?page=event-edit&id=' . (int) $event['parent_event_id']) ?>" class="font-semibold underline text-amber-900 hover:text-amber-950">Edit the parent event</a>
                    to change the schedule.
                <?php else: ?>
                    This event is a generated session in a series. Open the parent event in the admin event list to edit recurrence.
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php
            $recurrence_input_class = 'ta-input w-full';
            $recurrence_label_class = 'form-label';
            $recurrence_section_class = 'mb-4 p-4 rounded-xl border border-violet-100 bg-violet-50/50';
            require __DIR__ . '/includes/event-recurrence-fields.php';
            ?>
            <?php endif; ?>

            <div class="mb-4 rounded-xl border border-brand-100 bg-brand-50/80 p-4">
                <div class="mb-2 text-sm font-semibold text-brand-950 dark:text-brand-100">Check-In Window (optional)</div>
                <p class="mb-3 text-xs text-brand-900/80 dark:text-brand-200/80">Override when check-in opens/closes. If not set, check-in is allowed 1 hour before start time.</p>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="form-label text-brand-900 dark:text-brand-100">Check-In Opens</label>
                        <input type="time" name="checkin_window_start" value="<?= e($formData['checkin_window_start']) ?>"
                               class="ta-input w-full">
                    </div>
                    <div>
                        <label class="form-label text-brand-900 dark:text-brand-100">Check-In Closes</label>
                        <input type="time" name="checkin_window_end" value="<?= e($formData['checkin_window_end']) ?>"
                               class="ta-input w-full">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label" for="target_attendance">Target attendance (planning)</label>
                    <input type="number" id="target_attendance" name="target_attendance" min="1"
                           value="<?= e($formData['target_attendance'] ?? '') ?>"
                           class="ta-input w-full" placeholder="e.g. 200">
                </div>
                <div>
                    <label class="form-label" for="budget">Budget (optional)</label>
                    <div class="relative">
                        <span class="absolute left-3 top-2 text-gray-500 dark:text-gray-400">$</span>
                        <input type="number" id="budget" name="budget" min="0" step="0.01"
                               value="<?= e($formData['budget'] ?? '') ?>"
                               class="ta-input w-full pl-7" placeholder="5000">
                    </div>
                </div>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Date, time & location';
            $formSectionSubtitle = 'Schedule, recurrence, check-in window, location, and planning targets.';
            require __DIR__ . '/components/form-section.php';
            ?>

            <?php if ($checklistSvc->tablesExist()): ?>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">
                <a href="<?= e($adminBase . '/index.php?page=event-checklist&event_id=' . (int) $eventId) ?>" class="text-brand-600 font-medium hover:underline">Open event checklist</a>
                to update task assignments and progress.
            </p>
            <?php endif; ?>

            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" data-goto-step="1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary" data-goto-step="3">
                    Next: Settings
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $eventId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 3: Settings -->
        <div class="step-panel" id="panel-3">
            <?php ob_start(); ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label" for="capacity">Capacity</label>
                    <input type="number" id="capacity" name="capacity" min="1" value="<?= e((string) $formData['capacity']) ?>"
                           class="ta-input w-full"
                           placeholder="Unlimited if blank">
                </div>
                <div>
                    <label class="form-label" for="ticket_price">Ticket Price (USD)</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-semibold text-sm">$</span>
                        <input type="number" step="0.01" id="ticket_price" name="ticket_price" value="<?= e($formData['ticket_price']) ?>"
                               class="w-full border border-gray-200 rounded-xl pl-8 pr-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition-all dark:border-gray-700">
                    </div>
                    <p class="form-hint">Set to 0.00 for free events. When you use <strong>ticket types</strong> (Ticket Types tab), checkout uses those prices (Stripe). The single price here is a fallback when no ticket types apply.</p>
                </div>
            </div>

            <?php require __DIR__ . '/includes/event-pricing-tabs.php'; ?>

            <div class="space-y-3 mb-4">
                <label class="form-toggle cursor-pointer">
                    <input type="checkbox" name="registration_required" value="1" <?= !empty($formData['registration_required']) ? 'checked' : '' ?>>
                    <div>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Require RSVP</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Attendees must register to attend this event</p>
                    </div>
                </label>

                <label class="form-toggle cursor-pointer">
                    <input type="checkbox" name="allow_guest_rsvp" value="1" <?= !empty($formData['allow_guest_rsvp']) ? 'checked' : '' ?>>
                    <div>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Allow Guest RSVP</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Non-members can RSVP and get an email to complete their account</p>
                    </div>
                </label>

                <label class="form-toggle cursor-pointer">
                    <input type="checkbox" name="allow_bring_guests" value="1" <?= !empty($formData['allow_bring_guests']) ? 'checked' : '' ?>>
                    <div>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Allow bringing guests</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Attendees can indicate they are bringing additional guests to this event</p>
                    </div>
                </label>

                <label class="form-toggle cursor-pointer">
                    <input type="checkbox" name="is_potluck" value="1" <?= !empty($formData['is_potluck']) ? 'checked' : '' ?>>
                    <div>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Potluck / food signup</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">RSVP collects food category and item; public list is anonymous</p>
                    </div>
                </label>

                <label class="form-toggle cursor-pointer">
                    <input type="checkbox" name="collect_feedback" value="1" <?= !empty($formData['collect_feedback']) ? 'checked' : '' ?>>
                    <div>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Collect post-event feedback</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Email checked-in attendees one day after the event ends with a short feedback form</p>
                    </div>
                </label>
                <div id="potluck-allowed-slugs-block" class="ml-0 sm:ml-11 space-y-2 <?= empty($formData['is_potluck']) ? 'hidden' : '' ?>">
                    <label class="flex items-start gap-3 cursor-pointer max-w-xl">
                        <input type="hidden" name="potluck_show_bringing_prompt" value="0">
                        <input type="checkbox" name="potluck_show_bringing_prompt" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600" <?= !empty($formData['potluck_show_bringing_prompt']) ? 'checked' : '' ?>>
                        <span>
                            <span class="text-xs font-medium text-gray-800 dark:text-gray-100">Ask Yes/No before dish details</span>
                            <span class="block text-xs text-gray-500 mt-0.5 dark:text-gray-400">When unchecked, RSVP goes straight to food category and details (everyone is signing up a dish).</span>
                        </span>
                    </label>
                    <p class="text-xs font-medium text-gray-700 dark:text-gray-200">Food categories shown on RSVP</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Leave all checked to offer every category. Uncheck any you do not want for this event (e.g. disposables if the masjid supplies them).</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto rounded-lg border border-gray-200 bg-white p-3 dark:bg-gray-800 dark:border-gray-700">
                        <?php
                        $potSel = isset($formData['potluck_allowed_slugs']) && is_array($formData['potluck_allowed_slugs'])
                            ? $formData['potluck_allowed_slugs']
                            : PotluckCategoryService::orderedSlugs();
                        foreach (PotluckCategoryService::optionsForApi() as $potOpt) {
                            $pid = $potOpt['id'];
                            $checked = in_array($pid, $potSel, true) ? ' checked' : '';
                            ?>
                        <label class="flex items-start gap-2 text-xs text-gray-800 cursor-pointer dark:text-gray-100">
                            <input type="checkbox" name="potluck_allowed_slugs[]" value="<?= e($pid) ?>" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600"<?= $checked ?>>
                            <span><?= e($potOpt['label']) ?></span>
                        </label>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="registration_deadline">Registration Deadline</label>
                <input type="datetime-local" id="registration_deadline" name="registration_deadline" value="<?= e($formData['registration_deadline']) ?>"
                       class="ta-input w-full">
                <p class="form-hint">Leave blank for no deadline.</p>
            </div>

            <div class="mb-4 p-4 rounded-xl border border-gray-200 bg-gray-50/80 space-y-3 dark:bg-gray-800 dark:border-gray-700">
                <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Age &amp; gender eligibility (optional)</div>
                <p class="text-xs text-gray-500 dark:text-gray-400">RSVP is blocked when someone does not meet these rules. Guests verify with date of birth (and gender when required) on the guest RSVP form.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="form-label" for="min_age">Minimum age (at event date)</label>
                        <input type="number" min="0" max="150" name="min_age" id="min_age" class="ta-input w-full"
                               value="<?= $formData['min_age'] !== null && $formData['min_age'] !== '' ? (int) $formData['min_age'] : '' ?>" placeholder="No minimum">
                    </div>
                    <div>
                        <label class="form-label" for="max_age">Maximum age (at event date)</label>
                        <input type="number" min="0" max="150" name="max_age" id="max_age" class="ta-input w-full"
                               value="<?= $formData['max_age'] !== null && $formData['max_age'] !== '' ? (int) $formData['max_age'] : '' ?>" placeholder="No maximum">
                    </div>
                </div>
                <div>
                    <label class="form-label" for="gender_restriction">Gender requirement</label>
                    <select name="gender_restriction" id="gender_restriction" class="ta-select w-full sm:max-w-xs">
                        <option value="none" <?= ($formData['gender_restriction'] ?? 'none') === 'none' ? 'selected' : '' ?>>No restriction</option>
                        <option value="male" <?= ($formData['gender_restriction'] ?? '') === 'male' ? 'selected' : '' ?>>Male only</option>
                        <option value="female" <?= ($formData['gender_restriction'] ?? '') === 'female' ? 'selected' : '' ?>>Female only</option>
                        <option value="other" <?= ($formData['gender_restriction'] ?? '') === 'other' ? 'selected' : '' ?>>Other only</option>
                    </select>
                </div>
                <label class="flex items-start gap-3 cursor-pointer text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" name="enforce_restrictions_at_checkin" value="1" class="mt-1 h-4 w-4 rounded border-gray-300 text-brand-600" <?= !empty($formData['enforce_restrictions_at_checkin']) ? 'checked' : '' ?>>
                    <span>Also enforce at check-in (QR / admin). If unchecked, staff can check in anyone.</span>
                </label>
            </div>

            <div class="mb-4">
                <label class="form-label">Status</label>
                <div class="flex gap-3 mt-2">
                    <label class="flex items-center gap-2 border border-gray-200 rounded-xl px-4 py-2.5 cursor-pointer hover:border-brand-400 hover:bg-brand-50 transition-all flex-1 dark:border-gray-700">
                        <input type="radio" name="status" value="draft" <?= $formData['status'] === 'draft' ? 'checked' : '' ?>>
                        <div>
                            <span class="text-sm font-semibold">Draft</span>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Not publicly visible</p>
                        </div>
                    </label>
                    <label class="flex items-center gap-2 border border-gray-200 rounded-xl px-4 py-2.5 cursor-pointer hover:border-green-400 hover:bg-green-50 transition-all flex-1 dark:border-gray-700">
                        <input type="radio" name="status" value="published" <?= $formData['status'] === 'published' ? 'checked' : '' ?>>
                        <div>
                            <span class="text-sm font-semibold">Published</span>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Visible to members (per visibility above)</p>
                        </div>
                    </label>
                </div>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Capacity & Registration';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" data-goto-step="2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary" data-goto-step="4">
                    Next: RSVP questions
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $eventId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 4: Custom RSVP questions -->
        <div class="step-panel" id="panel-4">
            <?php ob_start(); ?>
            <p class="text-gray-500 text-sm mb-4 dark:text-gray-400">Add optional questions shown when members or guests RSVP for this event. Use &ldquo;Checkbox (multiple choices)&rdquo;, radio, or dropdown for options. &ldquo;Single checkbox&rdquo; is one yes/no field. Use &ldquo;Show only when&rdquo; for conditional questions.</p>
            <div id="questions-container" class="space-y-3"></div>
            <button type="button" id="add-question-btn" class="mt-3 text-brand-600 hover:text-brand-800 font-medium text-sm">+ Add question</button>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Custom RSVP questions (optional)';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" data-goto-step="3">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary" data-goto-step="5">
                    Team & leadership
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $eventId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 5: Team & leadership -->
        <?php if (!empty($checklistRoles)): ?>
        <div class="step-panel" id="panel-5" x-data="eventChecklistTeamStep(<?= htmlspecialchars(json_encode([
            'roles' => $checklistRoles,
            'staff' => $checklistStaff,
            'selected' => $formData['checklist_leadership'] ?? [],
        ]), ENT_QUOTES, 'UTF-8') ?>)" x-init="init()">
            <?php ob_start(); ?>
            <p class="text-sm text-gray-600 mb-4 dark:text-gray-300">Assign leadership roles for this event. <strong>Overall Event Lead is required.</strong> Each person may hold up to 3 roles.</p>
            <p id="team-step-error-edit" class="hidden text-sm text-red-600 mb-3"></p>
            <div class="space-y-4">
                <template x-for="role in roles" :key="role.id">
                    <div class="border border-gray-200 rounded-xl p-4 bg-white dark:bg-gray-800 dark:border-gray-700">
                        <label class="block text-sm font-semibold text-gray-800 mb-2 dark:text-gray-100" x-text="role.label + (role.role_key === 'overall_lead' ? ' *' : '')"></label>
                        <div class="relative">
                            <input type="text"
                                class="ta-input w-full"
                                :placeholder="assignments[role.id] ? selectedLabel(role.id) : 'Search staff by name or email…'"
                                x-model="search[role.id]"
                                @focus="openRole = role.id"
                                @input="openRole = role.id">
                            <input type="hidden" :name="'checklist_leadership[' + role.id + ']'" :value="assignments[role.id] || ''">
                            <div x-show="openRole === role.id && filteredStaff(role.id).length" x-cloak
                                class="absolute z-40 mt-1 w-full max-h-48 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:bg-gray-800 dark:border-gray-700">
                                <template x-for="person in filteredStaff(role.id)" :key="person.id">
                                    <button type="button" @click="pick(role.id, person)"
                                        class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span x-text="person.first_name + ' ' + person.last_name"></span>
                                        <span class="text-gray-500 text-xs ml-1" x-text="'(' + person.role + ')'"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <button type="button" x-show="assignments[role.id]" @click="clearRole(role.id)" class="text-xs text-gray-500 mt-1 hover:text-red-600">Clear</button>
                    </div>
                </template>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Team & leadership';
            $formSectionSubtitle = 'Updates apply when you save the event. Task assignments sync from these roles.';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" data-goto-step="4">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary event-edit-step-next" data-goto-step="6">
                    Review changes
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $eventId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>
        <?php else: ?>
        <div class="step-panel" id="panel-5">
            <div class="bento-card p-6 text-sm text-amber-800 bg-amber-50 dark:bg-amber-900/20 dark:text-amber-200 mb-4">
                Run database migration <code>083_event_checklists.sql</code> to enable Team & leadership assignment.
            </div>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" data-goto-step="4">Back</button>
                <button type="button" class="btn-primary" data-goto-step="6">Review</button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Step 6: Review -->
        <div class="step-panel" id="panel-6">
            <?php ob_start(); ?>
            <div class="review-summary mb-6" id="event-review-summary"></div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Review & Save';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" data-goto-step="5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="submit" class="btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    Save Changes
                </button>
                <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $eventId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php headcount_admin_js_emit('event-custom-questions.js?v=5'); ?>
<script type="application/json" id="event-edit-initial-questions"><?= headcount_json_for_script($preloadQuestions) ?></script>
<script type="application/json" id="event-edit-initial-tiers"><?= headcount_json_for_script($headcountTiersInitial) ?></script>
<script>
(function() {
    function headcountParseJsonScript(id, fallback) {
        var el = document.getElementById(id);
        if (!el || !el.textContent) {
            return fallback;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return fallback;
        }
    }
    function getChecked(name) {
        var el = document.querySelector('[name="' + name + '"]:checked');
        return el ? 'Yes' : 'No';
    }

    function updateReviewSummary() {
        var form = document.getElementById('event-edit-form');
        if (!form) return;
        
        function val(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? (el.value || '—') : '—';
        }
        function radioVal(name) {
            var el = form.querySelector('[name="' + name + '"]:checked');
            return el ? el.value : '—';
        }
        function checkVal(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el && el.checked ? 'Yes' : 'No';
        }
        
        var qc = document.getElementById('questions-container');
        var qRows = (qc && qc.querySelectorAll('.eq-question-row')) || [];
        var qCount = qRows ? qRows.length : 0;
        var stm = val('start_time_mode');
        var startSummary = val('start_time');
        if (stm === 'after_prayer') {
            var pn = val('prayer_name');
            var po = val('prayer_offset');
            startSummary = (pn ? pn + ' +' + po + ' min' : '—') + (val('start_time') ? (' (resolved ' + val('start_time') + ')') : '');
        }
        var rows = [
            ['Title', val('title')],
            ['Event Date', val('event_date')],
            ['Start', startSummary],
            ['End Time', val('end_time')],
            ['Location', val('location')],
            ['Virtual', checkVal('is_virtual')],
            ['Capacity', val('capacity') === '' ? 'Unlimited' : val('capacity')],
            ['Ticket Price', '$' + val('ticket_price')],
            ['Pricing', (function() {
                var r = form.querySelector('input.headcount-pricing-model-radio:checked');
                return r && r.value === 'headcount_tier' ? 'Tiered packages' : 'Per person';
            })()],
            ['Require RSVP', checkVal('registration_required')],
            ['Allow Guest RSVP', checkVal('allow_guest_rsvp')],
            ['Potluck / food signup', checkVal('is_potluck')],
            ['Collect post-event feedback', checkVal('collect_feedback')],
            ['Who can see (portal)', (function() {
                var h = form.querySelector('#headcount-event-visibility-post');
                if (h && h.value) return h.value;
                var u = form.querySelector('input[name="visibility_ui"]:checked');
                return u ? u.value : '—';
            })()],
            ['Status', radioVal('status')],
            ['Recurring', (function() {
                var cb = form.querySelector('#is_recurring');
                if (!cb) return '—';
                if (!cb.checked) return 'No';
                var rt = form.querySelector('#recurrence_type');
                return rt ? rt.value : 'Yes';
            })()],
            ['Custom RSVP questions', String(qCount)],
        ];
        
        var html = rows.map(function(r) {
            return '<div class="review-row"><span class="review-label">' + r[0] + '</span><span class="review-value">' + (r[1] || '—') + '</span></div>';
        }).join('');
        
        var el = document.getElementById('event-review-summary');
        if (el) el.innerHTML = html;
    }
    window.eventEditUpdateReviewSummary = updateReviewSummary;

    document.addEventListener('alpine:init', function() {
        if (typeof Alpine === 'undefined') return;
        Alpine.data('eventChecklistTeamStep', function(cfg) {
            cfg = cfg || {};
            var initial = cfg.selected || {};
            var assignments = {};
            Object.keys(initial).forEach(function(k) { assignments[k] = initial[k]; });
            return {
                roles: cfg.roles || [],
                staff: cfg.staff || [],
                assignments: assignments,
                search: {},
                openRole: null,
                filteredStaff: function(roleId) {
                    var q = (this.search[roleId] || '').toLowerCase();
                    var self = this;
                    return this.staff.filter(function(p) {
                        if (q && (p.first_name + ' ' + p.last_name + ' ' + p.email).toLowerCase().indexOf(q) === -1) return false;
                        var uid = String(p.id);
                        var count = 0;
                        Object.keys(self.assignments).forEach(function(rid) {
                            if (String(self.assignments[rid]) === uid) count++;
                        });
                        if (count >= 3 && String(self.assignments[roleId]) !== uid) return false;
                        return true;
                    });
                },
                pick: function(roleId, person) {
                    this.assignments[roleId] = person.id;
                    this.search[roleId] = person.first_name + ' ' + person.last_name;
                    this.openRole = null;
                },
                clearRole: function(roleId) {
                    delete this.assignments[roleId];
                    this.search[roleId] = '';
                },
                selectedLabel: function(roleId) {
                    var uid = this.assignments[roleId];
                    if (!uid) {
                        return '';
                    }
                    var p = this.staff.find(function(s) { return String(s.id) === String(uid); });
                    return p ? p.first_name + ' ' + p.last_name : '';
                },
                init: function() {
                    var self = this;
                    Object.keys(this.assignments).forEach(function(roleId) {
                        if (self.assignments[roleId]) {
                            self.search[roleId] = self.selectedLabel(roleId);
                        }
                    });
                },
                validate: function() {
                    var overall = this.roles.find(function(r) { return r.role_key === 'overall_lead'; });
                    var err = document.getElementById('team-step-error-edit');
                    if (!overall || !this.assignments[overall.id]) {
                        if (err) { err.textContent = 'Overall Event Lead is required.'; err.classList.remove('hidden'); }
                        return false;
                    }
                    var counts = {};
                    var self = this;
                    Object.keys(this.assignments).forEach(function(rid) {
                        var uid = self.assignments[rid];
                        counts[uid] = (counts[uid] || 0) + 1;
                    });
                    for (var uid in counts) {
                        if (counts[uid] > 3) {
                            if (err) { err.textContent = 'Each person may hold at most 3 leadership roles.'; err.classList.remove('hidden'); }
                            return false;
                        }
                    }
                    if (err) err.classList.add('hidden');
                    return true;
                }
            };
        });
    });

    window.eventEditTeamStepOk = function() {
        var panel = document.getElementById('panel-5');
        if (!panel || !panel._x_dataStack || !panel._x_dataStack[0]) return true;
        return panel._x_dataStack[0].validate();
    };
    window.eventEditValidateTeamStep = function() {};

    var initialQuestions = headcountParseJsonScript('event-edit-initial-questions', []);
    if (window.EventCustomQuestions) {
        EventCustomQuestions.mount('questions-container', { initialRows: initialQuestions, addButtonId: 'add-question-btn' });
    }

    (function headcountTierEditor() {
        var tbody = document.getElementById('headcount-tier-rows');
        var hidden = document.getElementById('headcount_pricing_tiers_json');
        var wrap = document.getElementById('headcount-tier-editor-wrap');
        var initialTiers = headcountParseJsonScript('event-edit-initial-tiers', []);

        function toggleWrap() {
            var r = document.querySelector('input.headcount-pricing-model-radio:checked');
            if (wrap) wrap.style.display = r && r.value === 'headcount_tier' ? 'block' : 'none';
        }
        document.querySelectorAll('.headcount-pricing-model-radio').forEach(function(el) {
            el.addEventListener('change', toggleWrap);
        });

        function addRow(minV, maxV, priceV) {
            if (!tbody) return;
            var tr = document.createElement('tr');
            tr.className = 'headcount-tier-row border-b border-gray-100';
            tr.innerHTML = '<td class="py-2 pr-2"><input type="number" min="1" class="tier-min w-full border border-gray-200 rounded-lg px-2 py-1.5 dark:border-gray-700" value="' + (minV != null ? minV : '') + '"></td>' +
                '<td class="py-2 pr-2"><input type="number" min="1" placeholder="blank = no max" class="tier-max w-full border border-gray-200 rounded-lg px-2 py-1.5 dark:border-gray-700" value="' + (maxV != null && maxV !== '' ? maxV : '') + '"></td>' +
                '<td class="py-2 pr-2"><input type="number" step="0.01" min="0" class="tier-price w-full border border-gray-200 rounded-lg px-2 py-1.5 dark:border-gray-700" value="' + (priceV != null ? priceV : '') + '"></td>' +
                '<td class="py-2"><button type="button" class="tier-remove text-red-600 text-xs font-medium hover:underline">Remove</button></td>';
            tr.querySelector('.tier-remove').addEventListener('click', function() { tr.remove(); });
            tbody.appendChild(tr);
        }

        if (tbody) {
            if (initialTiers && initialTiers.length) {
                initialTiers.forEach(function(t) {
                    addRow(t.min, t.max != null ? t.max : '', t.price);
                });
            } else {
                addRow(1, 1, '');
                addRow(2, 3, '');
            }
        }

        var addBtn = document.getElementById('headcount-tier-add');
        if (addBtn) addBtn.addEventListener('click', function() { addRow('', '', ''); });

        function serializeTiers() {
            if (!hidden || !tbody) return;
            var rows = tbody.querySelectorAll('tr.headcount-tier-row');
            var arr = [];
            rows.forEach(function(tr) {
                var minEl = tr.querySelector('.tier-min');
                var maxEl = tr.querySelector('.tier-max');
                var priceEl = tr.querySelector('.tier-price');
                var min = parseInt(minEl && minEl.value, 10);
                var maxStr = maxEl && maxEl.value.trim();
                var max = maxStr === '' ? null : parseInt(maxStr, 10);
                var price = priceEl && priceEl.value !== '' ? parseFloat(priceEl.value) : 0;
                if (!(min >= 1) || !(price > 0)) {
                    return;
                }
                arr.push({ min: min, max: max, price: price });
            });
            hidden.value = JSON.stringify(arr);
        }

        var form = document.getElementById('event-edit-form');
        if (form) {
            function syncVisibilityPosted() {
                var postEl = document.getElementById('headcount-event-visibility-post');
                if (!postEl) return;
                var picked = form.querySelector('input[name="visibility_ui"]:checked');
                postEl.value = picked ? picked.value : 'public';
            }
            form.addEventListener('change', function(e) {
                if (e.target && e.target.name === 'visibility_ui') syncVisibilityPosted();
            });
            form.addEventListener('submit', function() {
                syncVisibilityPosted();
                serializeTiers();
            });
        }
        toggleWrap();
    })();

    (function eventTicketTypeEditor() {
        var rowsEl = document.getElementById('event-ticket-type-rows');
        var addBtn = document.getElementById('event-ticket-type-add');
        if (!rowsEl || !addBtn) return;
        var nextIndex = <?= (int) count($ticketTypesRowsForTemplate) ?>;
        var tierVal = <?= headcount_json_for_script(EventHeadcountPricingService::MODEL_HEADCOUNT_TIER) ?>;
        var perVal = <?= headcount_json_for_script(EventHeadcountPricingService::MODEL_PER_PERSON) ?>;
        var dbHasNamedTickets = <?= $hasPersistedNamedTicketTypesFromDb ? 'true' : 'false' ?>;

        function anyTicketName() {
            var any = false;
            rowsEl.querySelectorAll('.headcount-ticket-type-name').forEach(function (inp) {
                if ((inp.value || '').trim()) any = true;
            });
            return any;
        }

        function syncTicketTypesVsTierRadio() {
            var tierRadio = document.querySelector('input.headcount-pricing-model-radio[value="' + tierVal + '"]');
            var perRadio = document.querySelector('input.headcount-pricing-model-radio[value="' + perVal + '"]');
            if (!tierRadio || !perRadio) return;
            var any = anyTicketName() || dbHasNamedTickets;
            tierRadio.disabled = any;
            if (any && tierRadio.checked) {
                perRadio.checked = true;
                perRadio.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (any && typeof window.eventPricingTabsActivate === 'function') {
                window.eventPricingTabsActivate('ticket-types');
            }
        }

        function wireRow(row) {
            var btn = row.querySelector('.event-ticket-type-remove');
            if (btn) btn.addEventListener('click', function () {
                if (rowsEl.querySelectorAll('.event-ticket-type-row').length <= 1) {
                    row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                    syncTicketTypesVsTierRadio();
                    return;
                }
                row.remove();
                syncTicketTypesVsTierRadio();
            });
            row.querySelectorAll('.headcount-ticket-type-name').forEach(function (inp) {
                inp.addEventListener('input', syncTicketTypesVsTierRadio);
            });
        }

        rowsEl.querySelectorAll('.event-ticket-type-row').forEach(wireRow);

        addBtn.addEventListener('click', function () {
            var i = nextIndex++;
            var wrap = document.createElement('div');
            wrap.className = 'event-ticket-type-row mb-3 p-3 rounded-xl border border-brand-100/80 bg-white space-y-2';
            wrap.innerHTML =
                '<div class="flex flex-wrap items-end gap-2">' +
                '<input type="text" name="ticket_types[' + i + '][name]" value="" placeholder="Name (e.g. Beginner — Early bird)" class="headcount-ticket-type-name flex-1 min-w-[140px] border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700">' +
                '<div class="relative w-28"><span class="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-xs">$</span>' +
                '<input type="number" name="ticket_types[' + i + '][price]" step="0.01" min="0" value="" placeholder="0" class="w-full border border-gray-200 rounded-lg pl-5 pr-2 py-2 text-sm dark:border-gray-700"></div>' +
                '<input type="number" name="ticket_types[' + i + '][quantity_limit]" min="0" value="" placeholder="Limit" class="w-24 border border-gray-200 rounded-lg px-2 py-2 text-sm dark:border-gray-700" title="Max qty (optional)">' +
                '<button type="button" class="event-ticket-type-remove text-rose-600 text-sm font-medium hover:underline px-2">Remove</button></div>' +
                '<div class="grid grid-cols-1 sm:grid-cols-3 gap-2">' +
                '<div><label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 dark:text-gray-400">Sale starts</label>' +
                '<input type="datetime-local" name="ticket_types[' + i + '][sale_starts_at]" value="" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs dark:border-gray-700"></div>' +
                '<div><label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 dark:text-gray-400">Sale ends</label>' +
                '<input type="datetime-local" name="ticket_types[' + i + '][sale_ends_at]" value="" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs dark:border-gray-700"></div>' +
                '<div><label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 dark:text-gray-400">Package group</label>' +
                '<input type="text" name="ticket_types[' + i + '][package_group]" value="" maxlength="64" placeholder="e.g. track" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs dark:border-gray-700"></div></div>';
            rowsEl.appendChild(wrap);
            wireRow(wrap);
            syncTicketTypesVsTierRadio();
        });

        rowsEl.addEventListener('input', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('headcount-ticket-type-name')) {
                syncTicketTypesVsTierRadio();
            }
        });

        syncTicketTypesVsTierRadio();
    })();

    (function potluckAllowedToggle() {
        var cb = document.querySelector('#event-edit-form input[name="is_potluck"]');
        var blk = document.getElementById('potluck-allowed-slugs-block');
        if (!cb || !blk) return;
        function sync() {
            if (cb.checked) blk.classList.remove('hidden');
            else blk.classList.add('hidden');
        }
        cb.addEventListener('change', sync);
        sync();
    })();
})();
</script>
<script>
(function () {
    function bootDescriptionEditor() {
        if (typeof window.initWYSIWYG !== 'function') return;
        window.initWYSIWYG('#description');
    }
    bootDescriptionEditor();
    document.addEventListener('DOMContentLoaded', bootDescriptionEditor);
    window.addEventListener('load', bootDescriptionEditor);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

