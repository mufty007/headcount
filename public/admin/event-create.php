<?php

/**
 * Create Event Page
 * Server-side rendered event creation form
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
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\PrayerTimesService;
use Headcount\Services\EventQuestionMergeService;
use Headcount\Services\EventHeadcountPricingService;
use Headcount\Services\AdminEventRecurrenceService;
use Headcount\Services\RecurringEventService;
use Headcount\Services\PotluckCategoryService;
use Headcount\Helpers\EventTicketTypesPersistence;
use Headcount\Services\FacilityService;

// Load helper functions

AuthMiddleware::requireCan('events.manage');

$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

$hasEventsVisibilityCol = headcount_events_has_visibility_column($db);

$hasEventFacilityCol = false;
$facilityOptions = [];
try {
    $hasEventFacilityCol = headcount_db_has_column($db, 'events', 'facility_id');
    if ($hasEventFacilityCol) {
        $facSvc = new FacilityService();
        if ($facSvc->tableExists()) {
            $facilityOptions = $facSvc->listForOrg($organizationId, ['status' => 'active']);
        }
    }
} catch (\Throwable $e) {
    error_log('event-create.php: facility options failed: ' . $e->getMessage());
}

$errors = [];
    $formData = [
        'title' => '',
        'description' => '',
        'event_date' => '',
        'start_time' => '',
        'end_time' => '',
        'location' => '',
        'facility_id' => '',
        'is_virtual' => false,
        'extra_details' => '',
        'category' => '',
        'capacity' => '',
        'ticket_price' => '0.00',
        'pricing_model' => EventHeadcountPricingService::MODEL_PER_PERSON,
        'registration_required' => false,
        'registration_deadline' => '',
        'min_age' => '',
        'max_age' => '',
        'gender_restriction' => 'none',
        'enforce_restrictions_at_checkin' => false,
        'allow_guest_rsvp' => false,
        'allow_bring_guests' => false,
        'is_potluck' => false,
        'potluck_show_bringing_prompt' => true,
        'potluck_allowed_slugs' => PotluckCategoryService::orderedSlugs(),
        'status' => 'draft',
        'visibility' => 'public',
        'categories' => [],
        'checkin_window_start' => '',
        'checkin_window_end' => '',
        'is_recurring' => false,
        'recurrence_type' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_days' => [],
        'recurrence_week_of_month' => '',
        'recurrence_end_type' => 'never',
        'recurrence_end_after_count' => '',
        'recurrence_end_date' => '',
        'custom_session_dates_text' => '',
        'session_registration_mode' => 'independent',
    ];
    $questionsPreload = [];
$normPostedCreate = ['tiers' => [], 'error' => null];

if (isPost()) {
    // Verify CSRF token
    CsrfMiddleware::verify();
    
    // Get form data
    $formData = [
        'title' => sanitize(post('title')),
        'description' => post('description'), // Allow HTML
        'event_date' => post('event_date'),
        'start_time' => post('start_time'),
        'end_time' => post('end_time'),
        'location' => sanitize(post('location')),
        'facility_id' => headcount_resolve_event_facility_id($db, (int) $organizationId, post('facility_id', '')),
        'is_virtual' => (bool)post('is_virtual'),
        'extra_details' => post('extra_details') ?: '',
        'category' => post('category'),
        'capacity' => post('capacity') ? (int)post('capacity') : null,
        'ticket_price' => post('ticket_price') ? (float)post('ticket_price') : 0.00,
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
        'potluck_show_bringing_prompt' => post('is_potluck') ? (post('potluck_show_bringing_prompt') ? 1 : 0) : 1,
        'potluck_allowed_slugs' => isset($_POST['potluck_allowed_slugs']) && is_array($_POST['potluck_allowed_slugs'])
            ? array_values(array_filter(array_map('strval', $_POST['potluck_allowed_slugs'])))
            : PotluckCategoryService::orderedSlugs(),
        'status' => post('status', 'draft'),
        'visibility' => headcount_post_visibility('visibility', 'public'),
        'categories' => $_POST['categories'] ?? [], // Array of category IDs/names
        'checkin_window_start' => post('checkin_window_start') ?: null,
        'checkin_window_end' => post('checkin_window_end') ?: null
    ];
    $recurrencePost = AdminEventRecurrenceService::inputFromPost();
    $formData['is_recurring'] = requestBoolFromInput($recurrencePost, 'is_recurring', false);
    $formData['recurrence_type'] = $recurrencePost['recurrence_type'] ?? 'weekly';
    $formData['recurrence_interval'] = max(1, (int) ($recurrencePost['recurrence_interval'] ?? 1));
    $formData['recurrence_days'] = isset($recurrencePost['recurrence_days']) && is_array($recurrencePost['recurrence_days'])
        ? array_values(array_map('intval', $recurrencePost['recurrence_days']))
        : [];
    $formData['recurrence_week_of_month'] = $recurrencePost['recurrence_week_of_month'] ?? '';
    $formData['recurrence_end_type'] = $recurrencePost['recurrence_end_type'] ?? 'never';
    $formData['recurrence_end_after_count'] = $recurrencePost['recurrence_end_after_count'] ?? '';
    $formData['recurrence_end_date'] = $recurrencePost['recurrence_end_date'] ?? '';
    $formData['custom_session_dates_text'] = post('custom_session_dates_text') ?: '';
    $srPost = strtolower(trim((string) post('session_registration_mode', 'independent')));
    $formData['session_registration_mode'] = in_array($srPost, ['independent', 'choose_one', 'all_sessions'], true)
        ? $srPost
        : 'independent';
    $questionsInput = $_POST['questions'] ?? [];
    if (!is_array($questionsInput)) $questionsInput = [];
    
    // Validate
    if (empty($formData['title'])) {
        $errors[] = 'Event title is required.';
    }
    
    if (empty($formData['event_date'])) {
        $errors[] = 'Event date is required.';
    } elseif (strtotime($formData['event_date']) < strtotime('today midnight')) {
        $errors[] = 'Event date cannot be in the past.';
    }
    
    if (empty($formData['location'])) {
        $errors[] = 'Location is required.';
    }

    if ($hasEventFacilityCol && ($formData['facility_id'] ?? null) === false) {
        $errors[] = 'Selected facility is not valid.';
        $formData['facility_id'] = null;
    }
    $facilityTimeErr = headcount_validate_event_facility_times(
        is_int($formData['facility_id'] ?? null) ? (int) $formData['facility_id'] : null,
        (string) ($formData['start_time'] ?? ''),
        (string) ($formData['end_time'] ?? '')
    );
    if ($facilityTimeErr !== null) {
        $errors[] = $facilityTimeErr;
    }
    
    if (empty($formData['category'])) {
        $errors[] = 'Category is required.';
    }
    $gr = strtolower(trim((string) ($formData['gender_restriction'] ?? 'none')));
    if (!in_array($gr, ['none', 'male', 'female', 'other'], true)) {
        $gr = 'none';
    }
    $formData['gender_restriction'] = $gr;
    $formData['visibility'] = \Headcount\Services\EventVisibilityService::normalize((string) ($formData['visibility'] ?? 'public'));
    if ($formData['min_age'] !== null && $formData['min_age'] < 0) {
        $errors[] = 'Minimum age cannot be negative.';
    }
    if ($formData['max_age'] !== null && $formData['max_age'] < 0) {
        $errors[] = 'Maximum age cannot be negative.';
    }
    if ($formData['min_age'] !== null && $formData['max_age'] !== null && $formData['min_age'] > $formData['max_age']) {
        $errors[] = 'Minimum age cannot be greater than maximum age.';
    }

    $tierSvcCreate = new EventHeadcountPricingService();
    $normPostedCreate = $tierSvcCreate->normalizeTiersFromInput(post('headcount_pricing_tiers_json'));
    if ($normPostedCreate['error'] !== null) {
        $errors[] = $normPostedCreate['error'];
    } elseif ($formData['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER) {
        $vCreate = $tierSvcCreate->validateTiersForSave($normPostedCreate['tiers']);
        if ($vCreate !== null) {
            $errors[] = $vCreate;
        }
    }

    if (requestBoolFromInput($recurrencePost, 'is_recurring', false)) {
        $rtype = strtolower(trim((string) ($recurrencePost['recurrence_type'] ?? 'weekly')));
        if ($rtype === 'weekly' && !recurrenceDaysProvided($recurrencePost)) {
            $errors[] = 'Select at least one weekday for weekly recurring events (Sunday counts as a weekday).';
        }
        if ($rtype === 'custom') {
            if (!$db->hasColumn('recurring_events', 'custom_dates')) {
                $errors[] = 'Specific dates require database migration 037 (recurring_events.custom_dates).';
            } elseif (!empty($recurrencePost['custom_session_dates_text_error'])) {
                $errors[] = (string) $recurrencePost['custom_session_dates_text_error'];
            } else {
                $encRes = RecurringEventService::encodeCustomDatesFromInputResult($recurrencePost, $formData['event_date']);
                if (!empty($encRes['error'])) {
                    $errors[] = $encRes['error'];
                } elseif (($encRes['json'] ?? null) === null) {
                    $errors[] = 'For “Specific dates”, add at least one additional session date (besides the main event date).';
                }
            }
        }
    }
    
    // If no errors, insert
    if (empty($errors)) {
        // Handle banner image upload
        $bannerImagePath = null;
        if (!empty($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $uploadConfig = $config['uploads'] ?? [];
                $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $uploadConfig['max_size'] = 5242880; // 5MB for banner images
                
                // Ensure upload_path is set correctly
                if (empty($uploadConfig['upload_path'])) {
                    $uploadConfig['upload_path'] = __DIR__ . '/../../uploads/';
                }
                
                $fileUpload = new \Headcount\Core\FileUpload($uploadConfig);
                $uploadResult = $fileUpload->upload($_FILES['banner_image'], 'event-banners');
                $bannerImagePath = 'event-banners/' . $uploadResult['filename'];
            } catch (\Exception $e) {
                $errors[] = 'Banner image upload failed: ' . $e->getMessage();
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

            $insertData = [
                'organization_id' => $organizationId,
                'title' => $formData['title'],
                'description' => $formData['description'] ?: null,
                'banner_image' => $bannerImagePath,
                'event_date' => $formData['event_date'],
                'start_time' => $formData['start_time'] ?: null,
                'end_time' => $formData['end_time'] ?: null,
                'location' => $formData['location'],
                'category' => $formData['category'],
                'capacity' => $formData['capacity'],
                'ticket_price' => $formData['ticket_price'],
                'registration_required' => $formData['registration_required'],
                'registration_deadline' => $formData['registration_deadline'],
                'status' => $formData['status'],
                'checkin_window_start' => $formData['checkin_window_start'],
                'checkin_window_end' => $formData['checkin_window_end'],
                'created_by' => $userId
            ];
            try {
                $evCols = $db->query("SHOW COLUMNS FROM events");
                $evColNames = array_column($evCols, 'Field');
                if ($hasEventsVisibilityCol) {
                    $insertData['visibility'] = \Headcount\Services\EventVisibilityService::normalize($formData['visibility']);
                }
                if (in_array('allow_guest_rsvp', $evColNames)) {
                    $insertData['allow_guest_rsvp'] = !empty($formData['allow_guest_rsvp']) ? 1 : 0;
                }
                if (in_array('allow_bring_guests', $evColNames)) {
                    $insertData['allow_bring_guests'] = !empty($formData['allow_bring_guests']) ? 1 : 0;
                }
                if (in_array('is_potluck', $evColNames)) {
                    $insertData['is_potluck'] = !empty($formData['is_potluck']) ? 1 : 0;
                }
                if (in_array('potluck_allowed_slugs', $evColNames, true)) {
                    $slugsPostCreate = isset($formData['potluck_allowed_slugs']) && is_array($formData['potluck_allowed_slugs'])
                        ? $formData['potluck_allowed_slugs']
                        : [];
                    $insertData['potluck_allowed_slugs'] = PotluckCategoryService::potluckAllowedSlugsJsonForStorage(
                        !empty($formData['is_potluck']),
                        $slugsPostCreate
                    );
                }
                if (in_array('potluck_show_bringing_prompt', $evColNames, true)) {
                    $insertData['potluck_show_bringing_prompt'] = !empty($formData['potluck_show_bringing_prompt']) ? 1 : 0;
                }
                if (in_array('is_virtual', $evColNames)) {
                    $insertData['is_virtual'] = !empty($formData['is_virtual']) ? 1 : 0;
                }
                if (in_array('facility_id', $evColNames, true)) {
                    $insertData['facility_id'] = !empty($formData['facility_id']) ? (int) $formData['facility_id'] : null;
                }
                if (in_array('extra_details', $evColNames)) {
                    $insertData['extra_details'] = $formData['extra_details'] ?: null;
                }
                if (in_array('prayer_name', $evColNames) && in_array('prayer_offset', $evColNames)) {
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
                                $insertData['start_time'] = $ct;
                                $insertData['prayer_name'] = $pn;
                                $insertData['prayer_offset'] = $off;
                            }
                        }
                    } else {
                        $insertData['prayer_name'] = null;
                        $insertData['prayer_offset'] = 0;
                    }
                }
                if (in_array('pricing_model', $evColNames)) {
                    $insertData['pricing_model'] = $formData['pricing_model'];
                }
                if (in_array('headcount_pricing_tiers', $evColNames)) {
                    $insertData['headcount_pricing_tiers'] = $formData['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER
                        ? json_encode($normPostedCreate['tiers'])
                        : null;
                }
                if (in_array('min_age', $evColNames)) {
                    $insertData['min_age'] = $formData['min_age'];
                }
                if (in_array('max_age', $evColNames)) {
                    $insertData['max_age'] = $formData['max_age'];
                }
                if (in_array('gender_restriction', $evColNames)) {
                    $insertData['gender_restriction'] = $formData['gender_restriction'];
                }
                if (in_array('enforce_restrictions_at_checkin', $evColNames)) {
                    $insertData['enforce_restrictions_at_checkin'] = !empty($formData['enforce_restrictions_at_checkin']) ? 1 : 0;
                }
                if (in_array('session_registration_mode', $evColNames, true)) {
                    $insertData['session_registration_mode'] = $formData['session_registration_mode'];
                }
            } catch (\Exception $e) { /* ignore */ }
            
            try {
                $db->beginTransaction();
                $eventId = $db->insert('events', $insertData);
                
                // Save categories to mapping table
                if (!empty($formData['categories'])) {
                    foreach ($formData['categories'] as $catVal) {
                        try {
                            $targetCatId = null;
                            if (is_numeric($catVal)) {
                                $targetCatId = (int)$catVal;
                            } else {
                                $existing = $db->queryOne("SELECT id FROM categories WHERE name = :name AND organization_id = :org_id", [
                                    'name' => $catVal,
                                    'org_id' => $organizationId
                                ]);
                                if ($existing) $targetCatId = $existing['id'];
                            }
                            
                            if ($targetCatId) {
                                $db->insert('event_categories', [
                                    'event_id' => $eventId,
                                    'category_id' => $targetCatId
                                ]);
                            }
                        } catch (\Exception $e) {
                            error_log("Could not save event category: " . $e->getMessage());
                        }
                    }
                }

                EventTicketTypesPersistence::replaceTicketTypesForEvent(
                    $db,
                    (int) $eventId,
                    EventTicketTypesPersistence::parseTicketTypesFromRequest($_POST)
                );

                $sync = AdminEventRecurrenceService::sync(
                    $db,
                    $organizationId,
                    (int) $eventId,
                    $formData['event_date'],
                    $recurrencePost,
                    false
                );
                if (!$sync['ok']) {
                    $db->rollback();
                    $errors[] = $sync['error'] ?? 'Recurring settings could not be saved.';
                } else {
                    $db->commit();
                    $gen = (int) ($sync['generated'] ?? 0);
                    $msg = 'Event created successfully!';
                    if ($gen > 0) {
                        $msg .= ' ' . $gen . ' additional recurring session(s) were generated.';
                    }
                    setFlash('success', $msg);
                
                    // Calculate base path for redirect
                    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
                    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
                    $basePath = rtrim($basePath, '/');
                    $adminBase = $basePath . '/admin';
                
                    try {
                        (new EventQuestionMergeService($db))->mergeForEvent((int) $eventId, $questionsInput);
                    } catch (\Exception $e) {
                        error_log("Could not save event questions: " . $e->getMessage());
                    }
                
                    Utilities::redirect($adminBase . '/?page=events');
                }
            } catch (\Exception $e) {
                try {
                    if ($db->getConnection()->inTransaction()) {
                        $db->rollback();
                    }
                } catch (\Throwable $t) {
                    /* ignore */
                }
                $errors[] = 'Failed to create event: ' . $e->getMessage();
            }
        }
    }
}

$ticketTypesInitial = [];
if (isPost() && !empty($errors)) {
    $ticketTypesInitial = EventTicketTypesPersistence::parseTicketTypesFromRequest($_POST);
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
$hasPersistedNamedTicketTypesFromDb = false;

// Get categories for this organization
$categories = $db->query("SELECT name, slug FROM categories WHERE organization_id = :org_id AND is_active = 1 ORDER BY sort_order, name", ['org_id' => $organizationId]);

// Calculate base path for assets (use from index.php if available)
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}
$assetsBase = $basePath . '/public/assets/';

$pageTitle = 'Create Event';
$currentPage = 'events';
$adminMainFullWidth = true;
$requiresQuillEditor = true;
$requiresEventWizard = true;
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in admin-event-wizard w-full min-w-0" style="width:100%;max-width:100%">
    <?php
    $pageHeaderTitle = 'Create New Event';
    $pageHeaderSubtitle = 'Set up a new event for your organization in a few simple steps.';
    $pageHeaderBreadcrumb = [
        ['label' => 'Events', 'url' => $adminBase . '/?page=events'],
        ['label' => 'Create Event'],
    ];
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if (!empty($errors)): ?>
        <div class="ta-alert ta-alert-error mb-6 flex-col items-start">
            <p class="font-semibold mb-1">Please fix the following errors:</p>
            <ul class="list-disc list-inside text-sm space-y-0.5">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <?php
    $flash = getFlash();
    if ($flash && $flash['type'] === 'success'):
    ?>
        <div class="ta-alert ta-alert-success mb-6">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>
    
    <!-- Step Progress -->
    <div class="multi-step-progress mb-8">
        <?php
        $stepLabels = ['Basics', 'Schedule', 'Registration', 'Options', 'Questions', 'Review'];
        for ($i = 1; $i <= 6; $i++):
        ?>
        <div class="step-item <?= $i === 1 ? 'active' : '' ?>" id="step-item-<?= $i ?>" data-wizard-step="<?= $i ?>" role="button" tabindex="0">
            <div class="step-circle"><?= $i ?></div>
            <span class="step-label"><?= $stepLabels[$i-1] ?></span>
        </div>
        <?php endfor; ?>
    </div>

    <form method="POST" action="" enctype="multipart/form-data" id="event-create-form">
        <input type="hidden" name="csrf_token" value="<?= CsrfMiddleware::getToken() ?>">
        <!-- Step 1: Basics -->
        <div class="event-step" data-step="1">
        <?php ob_start(); ?>
            <div class="mb-4">
                <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="title">
                    Event Title *
                </label>
                <input 
                    type="text" 
                    id="title" 
                    name="title" 
                    value="<?= e($formData['title']) ?>"
                    class="ta-input w-full"
                    required
                >
            </div>
            
            <!-- Description -->
            <div class="mb-4">
                <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="description">
                    Description
                </label>
                <textarea 
                    id="description" 
                    name="description" 
                    rows="4"
                    class="wysiwyg-editor ta-input w-full"
                ><?= headcount_wysiwyg_textarea_body($formData['description'] ?? '') ?></textarea>
            </div>
            
            <!-- Banner Image -->
            <div class="mb-4">
                <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="banner_image">
                    Event Banner Image
                </label>
                <input 
                    type="file" 
                    id="banner_image" 
                    name="banner_image" 
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    class="ta-input w-full"
                    onchange="previewBanner(event)"
                >
                <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Recommended: 1200×400px. Max size: 5MB. Formats: JPG, PNG, GIF, WEBP</p>
                <div id="banner-preview" class="mt-3 hidden">
                    <img id="banner-preview-img" src="" alt="Banner preview" class="max-w-full h-auto rounded-lg border border-gray-300" style="max-height: 200px;">
                </div>
            </div>

            <?php if ($hasEventsVisibilityCol): ?>
            <?php $visCreateVal = $formData['visibility'] ?? 'public'; ?>
            <div class="mb-6 p-4 rounded-xl border border-gray-200 bg-gray-50/80 dark:bg-gray-800 dark:border-gray-700">
                <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Who can see this event (when published)</label>
                <p class="text-xs text-gray-500 mb-3 dark:text-gray-400">Choose <strong>Public</strong> so members can see the event and RSVP in the portal. Internal events never appear there.</p>
                <input type="hidden" name="visibility" id="headcount-create-visibility-post" value="<?= e($visCreateVal) ?>">
                <div class="space-y-2" role="radiogroup" aria-label="Event visibility">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="radio" name="visibility_ui" value="public" class="mt-1 headcount-visibility-ui" <?= $visCreateVal === 'public' ? 'checked' : '' ?>>
                        <span><span class="font-medium text-gray-800 dark:text-gray-100">Public</span><span class="block text-xs text-gray-500 dark:text-gray-400">Listed for members and public calendar (when published).</span></span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="radio" name="visibility_ui" value="internal" class="mt-1 headcount-visibility-ui" <?= $visCreateVal === 'internal' ? 'checked' : '' ?>>
                        <span><span class="font-medium text-gray-800 dark:text-gray-100">Internal (staff only)</span><span class="block text-xs text-gray-500 dark:text-gray-400">Admins and coordinators only — not shown in the member portal.</span></span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="radio" name="visibility_ui" value="invite_only" class="mt-1 headcount-visibility-ui" <?= $visCreateVal === 'invite_only' ? 'checked' : '' ?>>
                        <span><span class="font-medium text-gray-800 dark:text-gray-100">Invite-only</span><span class="block text-xs text-gray-500 dark:text-gray-400">Only invited members see it in the portal and can RSVP.</span></span>
                    </label>
                </div>
            </div>
            <?php endif; ?>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Basic Information';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav event-step-nav" data-step="1">
                <button type="button" id="event-step-next-1" class="btn-primary" data-goto-step="2">Next: Schedule <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
                <a href="<?= e($adminBase . '/?page=events') ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>
            <!-- Step 2: Date, time & place -->
            <div class="event-step hidden space-y-4" data-step="2">
            <?php ob_start(); ?>
            <!-- Date and Time -->
            <div class="mb-4 p-4 bg-brand-50/50 border border-brand-100 rounded-lg">
                <p class="text-sm font-semibold text-gray-800 mb-2 dark:text-gray-100">Start time mode</p>
                <p class="text-xs text-gray-600 mb-3 dark:text-gray-300">Prayer-based start uses city &amp; country from <a href="<?= e($adminBase . '/index.php?page=settings') ?>" class="text-brand-600 underline">Settings</a> and the <a href="https://aladhan.com/prayer-times-api" target="_blank" rel="noopener noreferrer" class="text-brand-600 underline">Aladhan API</a>.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="start_time_mode">Mode</label>
                        <select name="start_time_mode" id="start_time_mode" class="ta-select w-full">
                            <option value="clock" <?= (post('start_time_mode', 'clock') === 'clock') ? 'selected' : '' ?>>Fixed clock time</option>
                            <option value="after_prayer" <?= (post('start_time_mode', '') === 'after_prayer') ? 'selected' : '' ?>>Minutes after a prayer</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="prayer_name">Prayer</label>
                        <select name="prayer_name" id="prayer_name" class="ta-select w-full">
                            <option value="">—</option>
                            <option value="Fajr" <?= post('prayer_name', '') === 'Fajr' ? 'selected' : '' ?>>Fajr</option>
                            <option value="Dhuhr" <?= post('prayer_name', '') === 'Dhuhr' ? 'selected' : '' ?>>Dhuhr</option>
                            <option value="Asr" <?= post('prayer_name', '') === 'Asr' ? 'selected' : '' ?>>Asr</option>
                            <option value="Maghrib" <?= post('prayer_name', '') === 'Maghrib' ? 'selected' : '' ?>>Maghrib</option>
                            <option value="Isha" <?= post('prayer_name', '') === 'Isha' ? 'selected' : '' ?>>Isha</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="prayer_offset">Minutes after</label>
                        <input type="number" name="prayer_offset" id="prayer_offset" min="0" max="600" value="<?= e(post('prayer_offset', '0')) ?>" class="ta-select w-full">
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="event_date">
                        Event Date *
                    </label>
                    <input 
                        type="date" 
                        id="event_date" 
                        name="event_date" 
                        value="<?= e($formData['event_date']) ?>"
                        class="ta-input w-full"
                        required
                    >
                </div>
                
                <div>
                    <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="start_time">
                        Start Time
                    </label>
                    <input 
                        type="time" 
                        id="start_time" 
                        name="start_time" 
                        value="<?= e($formData['start_time']) ?>"
                        class="ta-input w-full"
                    >
                </div>
                
                <div>
                    <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="end_time">
                        End Time
                    </label>
                    <input 
                        type="time" 
                        id="end_time" 
                        name="end_time" 
                        value="<?= e($formData['end_time']) ?>"
                        class="ta-input w-full"
                    >
                </div>
            </div>

            <?php require __DIR__ . '/includes/event-recurrence-fields.php'; ?>
            
            <!-- Check-In Window -->
            <div class="mb-4 rounded-xl border border-brand-200 bg-brand-50/80 p-4">
                <h3 class="mb-3 text-sm font-bold text-gray-700 dark:text-gray-200">Check-In Window (Optional)</h3>
                <p class="mb-3 text-xs text-gray-600 dark:text-gray-300">Set custom check-in times. If not set, check-in will be allowed 1 hour before the event start time.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="checkin_window_start">
                            Check-In Window Start
                        </label>
                        <input 
                            type="time" 
                            id="checkin_window_start" 
                            name="checkin_window_start" 
                            value="<?= e($formData['checkin_window_start'] ?? '') ?>"
                            class="ta-input w-full"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="checkin_window_end">
                            Check-In Window End
                        </label>
                        <input 
                            type="time" 
                            id="checkin_window_end" 
                            name="checkin_window_end" 
                            value="<?= e($formData['checkin_window_end'] ?? '') ?>"
                            class="ta-input w-full"
                        >
                    </div>
                </div>
            </div>
            
            <!-- Virtual event -->
            <div class="mb-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_virtual" value="1" <?= !empty($formData['is_virtual']) ? 'checked' : '' ?>
                           class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                    <span class="text-gray-700 font-medium dark:text-gray-200">Virtual event</span>
                </label>
                <p class="text-sm text-gray-500 mt-1 ml-6 dark:text-gray-400">Use a Zoom or Google Meet link as the location</p>
            </div>

            <!-- Location and Category -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="location">
                        Location *
                    </label>
                    <p class="text-xs text-gray-500 mb-1 dark:text-gray-400"><?= !empty($formData['is_virtual']) ? 'e.g. Zoom or Google Meet link' : 'Venue name or address' ?></p>
                    <input 
                        type="text" 
                        id="location" 
                        name="location" 
                        value="<?= e($formData['location']) ?>"
                        placeholder="<?= !empty($formData['is_virtual']) ? 'https://zoom.us/j/... or https://meet.google.com/...' : '' ?>"
                        class="ta-input w-full"
                        required
                    >
                </div>
                
                <div x-data='{
                    open: false,
                    search: "",
                    selected: <?= json_encode(array_values((array)($formData["categories"] ?? []))) ?>,
                    categories: <?= json_encode($categories ?? []) ?>,
                    get filtered() {
                        if (!this.search) return this.categories.filter(c => !this.isSelected(c));
                        return this.categories.filter(c => 
                            c.name.toLowerCase().includes(this.search.toLowerCase()) && 
                            !this.isSelected(c)
                        );
                    },
                    isSelected(cat) {
                        const val = cat.id || cat.name;
                        return this.selected.includes(String(val));
                    },
                    toggle(cat) {
                        const val = cat.id || cat.name;
                        if (!this.isSelected(cat)) {
                            this.selected.push(String(val));
                        }
                        this.search = "";
                    },
                    remove(val) {
                        this.selected = this.selected.filter(v => v != val);
                    }
                }' class="relative">
                    <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">
                        Categories *
                    </label>
                    
                    <!-- Chips Area -->
                    <div class="min-h-[46px] p-2 border border-gray-300 rounded-lg bg-white flex flex-wrap gap-2 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20 transition-all cursor-text dark:bg-gray-800"
                         @click="$refs.catInput.focus()">
                        <template x-for="val in selected" :key="val">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-brand-50 text-brand-700 text-xs font-bold rounded-md border border-brand-100 uppercase tracking-wider">
                                <span class="w-2 h-2 rounded-full" :style="'background-color: ' + (categories.find(c => (c.id || c.name) == val)?.color || '#3B82F6')"></span>
                                <span x-text="categories.find(c => (c.id || c.name) == val)?.name || val"></span>
                                <button type="button" @click.stop="remove(val)" class="text-brand-400 hover:text-brand-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                                <!-- Hidden inputs for form submission -->
                                <input type="hidden" name="categories[]" :value="val">
                            </span>
                        </template>
                        <input 
                            x-ref="catInput"
                            x-model="search"
                            @focus="open = true"
                            @click.away="open = false"
                            @keydown.escape="open = false"
                            placeholder="Type to search categories..."
                            class="flex-1 min-w-[150px] outline-none text-sm py-1"
                        >
                    </div>

                    <!-- Dropdown -->
                    <div x-show="open && filtered.length > 0" 
                         x-cloak
                         class="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white py-1 shadow-card dark:bg-gray-800 dark:border-gray-700"
                         x-transition>
                        <template x-for="cat in filtered" :key="cat.id || cat.slug || cat.name">
                            <button type="button" 
                                    @click="toggle(cat)"
                                    class="w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 flex items-center gap-3 transition-colors dark:bg-gray-800">
                                <span class="w-3 h-3 rounded-full" :style="'background-color: ' + (cat.color || '#3B82F6')"></span>
                                <span class="font-medium text-gray-700 dark:text-gray-200" x-text="cat.name"></span>
                            </button>
                        </template>
                    </div>
                    
                    <input type="hidden" name="category" :value="selected[0] || ''"> <!-- Legacy fallback -->
                </div>
            </div>

            <?php if ($hasEventFacilityCol && !empty($facilityOptions)): ?>
            <div class="mb-4">
                <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="facility_id">Link to facility (optional)</label>
                <select id="facility_id" name="facility_id"
                        class="ta-input w-full bg-white dark:bg-gray-800">
                    <option value="">None — no facility block</option>
                    <?php foreach ($facilityOptions as $fac): ?>
                        <option value="<?= (int) $fac['id'] ?>" <?= (string) ($formData['facility_id'] ?? '') === (string) (int) $fac['id'] ? 'selected' : '' ?>>
                            <?= e($fac['name'] ?? 'Facility') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Blocks member and guest facility bookings only when status is <strong>Published</strong>. Requires start and end time. Does not change the location field above.</p>
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="extra_details">Extra details (optional)</label>
                <p class="text-sm text-gray-500 mb-1 dark:text-gray-400">Additional info shown on the event details page for admins</p>
                <textarea id="extra_details" name="extra_details" rows="3" placeholder="Internal notes or extra event details..."
                    class="ta-input w-full"><?= e($formData['extra_details']) ?></textarea>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Schedule & Location';
            $formSectionSubtitle = 'Date, time, location, and prayer-based start options.';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav event-step-nav hidden" data-step="2">
                <button type="button" class="event-step-back btn-secondary" data-goto-step="1"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg> Back</button>
                <button type="button" class="event-step-next btn-primary" data-goto-step="3">Next <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
            </div>
            </div>
            <!-- Step 3: Registration & capacity -->
            <div class="event-step hidden space-y-4" data-step="3">
            <?php ob_start(); ?>
            <!-- Capacity and Price -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="capacity">
                        Capacity (optional)
                    </label>
                    <input 
                        type="number" 
                        id="capacity" 
                        name="capacity" 
                        value="<?= e($formData['capacity']) ?>"
                        min="1"
                        class="ta-input w-full"
                    >
                </div>
                
                <div>
                    <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="ticket_price">
                        Ticket Price (leave 0 for free)
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-2 text-gray-500 dark:text-gray-400">$</span>
                        <input 
                            type="number" 
                            id="ticket_price" 
                            name="ticket_price" 
                            value="<?= e($formData['ticket_price']) ?>"
                            min="0"
                            step="0.01"
                            class="w-full border border-gray-300 rounded-lg pl-8 pr-4 py-2 focus:outline-none focus:border-brand-500"
                        >
                    </div>
                    <p class="text-xs text-gray-500 mt-1 dark:text-gray-400">When you use <strong>ticket types</strong> (Ticket Types tab), checkout uses those prices. Leave 0 for free or as a fallback when no ticket types apply.</p>
                </div>
            </div>

            <?php require __DIR__ . '/includes/event-pricing-tabs.php'; ?>

            <div class="mb-6 p-6 bg-gray-50 rounded-2xl border border-gray-100 dark:bg-gray-800 dark:border-gray-800">
                <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4">Settings</h4>
                <div class="space-y-4">
                    <label class="flex items-start gap-4 cursor-pointer group">
                        <div class="flex items-center h-5 mt-0.5">
                            <input type="checkbox" name="registration_required" value="1" <?= $formData['registration_required'] ? 'checked' : '' ?> 
                                   class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600 transition-all cursor-pointer">
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Require RSVP</span>
                            <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Attendees must register to attend</p>
                        </div>
                    </label>

                    <label class="flex items-start gap-4 cursor-pointer group">
                        <div class="flex items-center h-5 mt-0.5">
                            <input type="checkbox" name="allow_guest_rsvp" value="1" <?= !empty($formData['allow_guest_rsvp']) ? 'checked' : '' ?> 
                                   class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600 transition-all cursor-pointer">
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Allow guest RSVP</span>
                            <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Non-members can RSVP once and get an email to complete their account</p>
                        </div>
                    </label>

                    <label class="flex items-start gap-4 cursor-pointer group">
                        <div class="flex items-center h-5 mt-0.5">
                            <input type="checkbox" name="allow_bring_guests" value="1" <?= !empty($formData['allow_bring_guests']) ? 'checked' : '' ?> 
                                   class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600 transition-all cursor-pointer">
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Allow bringing guests</span>
                            <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Attendees can indicate they are bringing additional guests to this event</p>
                        </div>
                    </label>

                    <label class="flex items-start gap-4 cursor-pointer group">
                        <div class="flex items-center h-5 mt-0.5">
                            <input type="checkbox" name="is_potluck" value="1" <?= !empty($formData['is_potluck']) ? 'checked' : '' ?>
                                   class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600 transition-all cursor-pointer">
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Potluck / food signup</span>
                            <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">RSVP asks for a food category and what they are bringing; the public list shows items without names</p>
                        </div>
                    </label>
                    <div id="potluck-allowed-slugs-block-create" class="pl-9 space-y-2 <?= empty($formData['is_potluck']) ? 'hidden' : '' ?>">
                        <label class="flex items-start gap-3 cursor-pointer max-w-xl">
                            <input type="hidden" name="potluck_show_bringing_prompt" value="0">
                            <input type="checkbox" name="potluck_show_bringing_prompt" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600" <?= !empty($formData['potluck_show_bringing_prompt']) ? 'checked' : '' ?>>
                            <span>
                                <span class="text-xs font-medium text-gray-800 dark:text-gray-100">Ask Yes/No before dish details</span>
                                <span class="block text-xs text-gray-500 mt-0.5 dark:text-gray-400">When unchecked, RSVP goes straight to food category and details (everyone is signing up a dish).</span>
                            </span>
                        </label>
                        <p class="text-xs font-medium text-gray-700 dark:text-gray-200">Food categories shown on RSVP</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Leave all checked for every category. Uncheck any you do not want for this event.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-3 dark:bg-gray-800 dark:border-gray-700">
                            <?php
                            $potSelCreate = isset($formData['potluck_allowed_slugs']) && is_array($formData['potluck_allowed_slugs'])
                                ? $formData['potluck_allowed_slugs']
                                : PotluckCategoryService::orderedSlugs();
                            foreach (PotluckCategoryService::optionsForApi() as $potOptC) {
                                $pidc = $potOptC['id'];
                                $checkedC = in_array($pidc, $potSelCreate, true) ? ' checked' : '';
                                ?>
                            <label class="flex items-start gap-2 text-xs text-gray-800 cursor-pointer dark:text-gray-100">
                                <input type="checkbox" name="potluck_allowed_slugs[]" value="<?= e($pidc) ?>" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600"<?= $checkedC ?>>
                                <span><?= e($potOptC['label']) ?></span>
                            </label>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Registration Deadline -->
            <div class="mb-4">
                <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200" for="registration_deadline">
                    Registration Deadline (optional)
                </label>
                <input 
                    type="datetime-local" 
                    id="registration_deadline" 
                    name="registration_deadline" 
                    value="<?= e($formData['registration_deadline']) ?>"
                    class="ta-input w-full"
                >
            </div>

            <div class="mb-4 p-4 rounded-xl border border-gray-200 bg-gray-50/80 space-y-3 dark:bg-gray-800 dark:border-gray-700">
                <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Age &amp; gender eligibility (optional)</div>
                <p class="text-xs text-gray-500 dark:text-gray-400">RSVP is blocked when someone does not meet these rules. Members use profile/family DOB; guests verify on the guest RSVP form.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-1 dark:text-gray-200" for="min_age">Minimum age (at event date)</label>
                        <input type="number" min="0" max="150" name="min_age" id="min_age" value="<?= $formData['min_age'] !== null && $formData['min_age'] !== '' ? (int) $formData['min_age'] : '' ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-500" placeholder="No minimum">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-1 dark:text-gray-200" for="max_age">Maximum age (at event date)</label>
                        <input type="number" min="0" max="150" name="max_age" id="max_age" value="<?= $formData['max_age'] !== null && $formData['max_age'] !== '' ? (int) $formData['max_age'] : '' ?>"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-500" placeholder="No maximum">
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-1 dark:text-gray-200" for="gender_restriction">Gender requirement</label>
                    <select name="gender_restriction" id="gender_restriction" class="w-full sm:w-64 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-brand-500">
                        <option value="none" <?= ($formData['gender_restriction'] ?? 'none') === 'none' ? 'selected' : '' ?>>No restriction</option>
                        <option value="male" <?= ($formData['gender_restriction'] ?? '') === 'male' ? 'selected' : '' ?>>Male only</option>
                        <option value="female" <?= ($formData['gender_restriction'] ?? '') === 'female' ? 'selected' : '' ?>>Female only</option>
                        <option value="other" <?= ($formData['gender_restriction'] ?? '') === 'other' ? 'selected' : '' ?>>Other only</option>
                    </select>
                </div>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="enforce_restrictions_at_checkin" value="1" <?= !empty($formData['enforce_restrictions_at_checkin']) ? 'checked' : '' ?> class="mt-1 h-4 w-4 rounded border-gray-300 text-brand-600">
                    <span class="text-sm text-gray-700 dark:text-gray-200">Also enforce at check-in (QR / admin). If unchecked, staff can check in anyone.</span>
                </label>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Registration & Capacity';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav event-step-nav hidden" data-step="3">
                <button type="button" class="event-step-back btn-secondary" data-goto-step="2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg> Back</button>
                <button type="button" class="event-step-next btn-primary" data-goto-step="4">Next <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
            </div>
            </div>
            <!-- Step 4: Options -->
            <div class="event-step hidden space-y-4" data-step="4">
            <?php ob_start(); ?>
            <!-- Status -->
            <div class="mb-6">
                <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">
                    Status
                </label>
                <div class="flex gap-4">
                    <label class="flex items-center">
                        <input 
                            type="radio" 
                            name="status" 
                            value="draft"
                            <?= $formData['status'] === 'draft' ? 'checked' : '' ?>
                            class="mr-2"
                        >
                        <span class="text-gray-700 dark:text-gray-200">Save as Draft</span>
                    </label>
                    <label class="flex items-center">
                        <input 
                            type="radio" 
                            name="status" 
                            value="published"
                            <?= $formData['status'] === 'published' ? 'checked' : '' ?>
                            class="mr-2"
                        >
                        <span class="text-gray-700 dark:text-gray-200">Publish Now</span>
                    </label>
                </div>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Publishing Options';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav event-step-nav hidden" data-step="4">
                <button type="button" class="event-step-back btn-secondary" data-goto-step="3"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg> Back</button>
                <button type="button" class="event-step-next btn-primary" data-goto-step="5">Next <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
            </div>
            </div>
            <!-- Step 5: Custom questions -->
            <div class="event-step hidden" data-step="5">
                <?php ob_start(); ?>
                <p class="text-gray-500 text-sm mb-4 dark:text-gray-400">Add optional questions shown when members or guests RSVP for this event. You can show a question only when a previous answer matches (conditional questions).</p>
                <div id="questions-container" class="space-y-3"></div>
                <button type="button" id="add-question-btn" class="mt-3 text-brand-600 hover:text-brand-800 font-medium text-sm">+ Add question</button>
                <?php
                $formSectionContent = ob_get_clean();
                $formSectionTitle = 'Custom Questions (Optional)';
                require __DIR__ . '/components/form-section.php';
                ?>
                <div class="form-sticky-footer step-nav event-step-nav hidden" data-step="5">
                    <button type="button" class="event-step-back btn-secondary" data-goto-step="4"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg> Back</button>
                    <button type="button" class="event-step-next btn-primary" data-goto-step="6">Review <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
                </div>
            </div>
            <!-- Step 6: Review & submit -->
            <div class="event-step hidden" data-step="6">
                <?php ob_start(); ?>
                <div class="review-summary mb-6" id="review-summary"></div>
                <?php
                $formSectionContent = ob_get_clean();
                $formSectionTitle = 'Review & Submit';
                require __DIR__ . '/components/form-section.php';
                ?>
                <div class="form-sticky-footer step-nav event-step-nav hidden" data-step="6">
                    <button type="button" id="event-step-back-6" class="btn-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                        Back
                    </button>
                    <button type="submit" class="btn-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Create Event
                    </button>
                    <a href="<?= e($adminBase . '/?page=events') ?>" class="btn-secondary">Cancel</a>
                </div>
            </div>
        </form>
</div>

<?php headcount_admin_js_emit('event-custom-questions.js?v=5'); ?>
<script>
function previewBanner(event) {
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('banner-preview');
            const img = document.getElementById('banner-preview-img');
            img.src = e.target.result;
            preview.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    }
}
</script>
<script>
(function() {
    function updateReviewSummary() {
        var form = document.getElementById('event-create-form');
        if (!form) return;
        var title = (form.querySelector('[name="title"]') || {}).value || '—';
        var date = (form.querySelector('[name="event_date"]') || {}).value || '—';
        var location = (form.querySelector('[name="location"]') || {}).value || '—';
        var category = (form.querySelector('[name="category"]') || {}).value || '—';
        var capacity = (form.querySelector('[name="capacity"]') || {}).value || 'Unlimited';
        var price = (form.querySelector('[name="ticket_price"]') || {}).value || '0';
        var status = (form.querySelector('[name="status"]:checked') || {}).value || 'draft';
        var qc = document.getElementById('questions-container');
        var qCount = (qc && qc.querySelectorAll('.eq-question-row').length) || 0;
        var html = '<p><strong>Title:</strong> ' + (title || '—') + '</p>';
        html += '<p><strong>Date:</strong> ' + date + '</p>';
        html += '<p><strong>Location:</strong> ' + location + '</p>';
        html += '<p><strong>Category:</strong> ' + category + '</p>';
        html += '<p><strong>Capacity:</strong> ' + capacity + '</p>';
        html += '<p><strong>Ticket price:</strong> $' + price + '</p>';
        html += '<p><strong>Status:</strong> ' + status + '</p>';
        html += '<p><strong>Custom questions:</strong> ' + Math.floor(qCount) + '</p>';
        var el = document.getElementById('review-summary');
        if (el) {
            el.classList.add('review-summary');
            var pr = (form.querySelector('input.headcount-pricing-model-radio:checked') || {}).value === 'headcount_tier' ? 'Tiered packages' : 'Per person';
            var recCb = form.querySelector('#is_recurring');
            var recSummary = recCb && recCb.checked ? ((form.querySelector('#recurrence_type') || {}).value || 'custom') : 'No';
            var vis = (function() {
                var h = form.querySelector('#headcount-create-visibility-post');
                var v = (h && h.value) ? h.value : '';
                if (!v) {
                    var r = form.querySelector('input[name="visibility_ui"]:checked');
                    v = r ? r.value : 'public';
                }
                if (v === 'internal') return 'Internal (staff only)';
                if (v === 'invite_only') return 'Invite-only';
                return 'Public';
            })();
            var rows = [
                ['Title', title], ['Date', date], ['Location', location], ['Category', category],
                ['Capacity', capacity], ['Ticket Price', '$' + price], ['Pricing', pr], ['Recurring', recSummary],
                ['Who can see (portal)', vis], ['Status', status], ['Questions', Math.floor(qCount) + '']
            ];
            el.innerHTML = rows.map(function(r) {
                return '<div class="review-row"><span class="review-label">' + r[0] + '</span><span class="review-value">' + (r[1] || '—') + '</span></div>';
            }).join('');
        }
    }
    window.eventCreateUpdateReviewSummary = updateReviewSummary;

    if (window.EventCustomQuestions) {
        EventCustomQuestions.mount('questions-container', { initialRows: [], addButtonId: 'add-question-btn' });
    }

    (function headcountTierEditorCreate() {
        var tbody = document.getElementById('headcount-tier-rows');
        var hidden = document.getElementById('headcount_pricing_tiers_json');
        var wrap = document.getElementById('headcount-tier-editor-wrap');
        var initialTiers = <?= json_encode($normPostedCreate['tiers'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

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

        var form = document.getElementById('event-create-form');
        if (form) {
            function syncCreateVisibilityPosted() {
                var postEl = document.getElementById('headcount-create-visibility-post');
                if (!postEl) return;
                var picked = form.querySelector('input[name="visibility_ui"]:checked');
                postEl.value = picked ? picked.value : 'public';
            }
            form.addEventListener('change', function(e) {
                if (e.target && e.target.name === 'visibility_ui') syncCreateVisibilityPosted();
            });
            form.addEventListener('submit', function() {
                syncCreateVisibilityPosted();
                serializeTiers();
            });
        }
        toggleWrap();
    })();

    (function eventTicketTypeEditorCreate() {
        var rowsEl = document.getElementById('event-ticket-type-rows');
        var addBtn = document.getElementById('event-ticket-type-add');
        if (!rowsEl || !addBtn) return;
        var nextIndex = <?= (int) count($ticketTypesRowsForTemplate) ?>;
        var tierVal = <?= json_encode(EventHeadcountPricingService::MODEL_HEADCOUNT_TIER, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        var perVal = <?= json_encode(EventHeadcountPricingService::MODEL_PER_PERSON, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
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

    (function potluckAllowedToggleCreate() {
        var cb = document.querySelector('#event-create-form input[name="is_potluck"]');
        var blk = document.getElementById('potluck-allowed-slugs-block-create');
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
