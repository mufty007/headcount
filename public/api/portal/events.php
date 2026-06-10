<?php

/**
 * Portal Events API
 * Public event listing (no authentication required)
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
use Headcount\Helpers\OrgTimeZone;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Services\EventEligibilityService;
use Headcount\Services\EventSeriesHelper;
use Headcount\Services\PotluckCategoryService;
use Headcount\Services\RSVPService;
use Headcount\Services\EventPeopleService;
use Headcount\Services\EventVisibilityService;
use Headcount\Services\EventTicketTypeRulesService;

/**
 * Banner path for API output: use instance row or fall back to parent series row.
 *
 * @param array<int,string|null> $parentBannerById
 */
function portal_resolve_event_banner_path(Database $db, array $event, array $parentBannerById = []): string
{
    $own = isset($event['banner_image']) ? trim((string) $event['banner_image']) : '';
    if ($own !== '') {
        return $own;
    }
    $pid = isset($event['parent_event_id']) ? (int) $event['parent_event_id'] : 0;
    if ($pid <= 0) {
        return '';
    }
    if (array_key_exists($pid, $parentBannerById)) {
        $p = trim((string) ($parentBannerById[$pid] ?? ''));
        return $p;
    }
    $row = $db->queryOne('SELECT banner_image FROM events WHERE id = ?', [$pid]);
    if ($row && trim((string) ($row['banner_image'] ?? '')) !== '') {
        return trim((string) $row['banner_image']);
    }
    return '';
}

/**
 * Decode HTML entities on user-facing text fields for portal JSON (titles, descriptions, etc.).
 */
function portal_apply_event_text_decode(array &$event): void
{
    foreach (['title', 'location'] as $k) {
        if (array_key_exists($k, $event) && $event[$k] !== null && $event[$k] !== '') {
            $event[$k] = headcount_flatten_ampersand_in_plain_text(trim((string) $event[$k]));
        }
    }
    if (array_key_exists('description', $event) && $event['description'] !== null && $event['description'] !== '') {
        $event['description'] = headcount_undo_nested_html_entity_encoding(trim((string) $event['description']));
    }
    if (!empty($event['categories']) && is_array($event['categories'])) {
        foreach ($event['categories'] as &$c) {
            if (isset($c['name']) && $c['name'] !== '') {
                $c['name'] = Utilities::decodeHtmlEntities($c['name']);
            }
        }
        unset($c);
    }
    if (isset($event['category']) && $event['category'] !== '') {
        $event['category'] = Utilities::decodeHtmlEntities($event['category']);
    }
    if (!empty($event['ticket_types']) && is_array($event['ticket_types'])) {
        foreach ($event['ticket_types'] as &$tt) {
            if (isset($tt['name']) && $tt['name'] !== '') {
                $tt['name'] = Utilities::decodeHtmlEntities($tt['name']);
            }
        }
        unset($tt);
    }
    if (!empty($event['ticket_sale_countdown']) && is_array($event['ticket_sale_countdown'])) {
        if (isset($event['ticket_sale_countdown']['detail']) && $event['ticket_sale_countdown']['detail'] !== '') {
            $event['ticket_sale_countdown']['detail'] = Utilities::decodeHtmlEntities((string) $event['ticket_sale_countdown']['detail']);
        }
        if (isset($event['ticket_sale_countdown']['headline']) && $event['ticket_sale_countdown']['headline'] !== '') {
            $event['ticket_sale_countdown']['headline'] = Utilities::decodeHtmlEntities((string) $event['ticket_sale_countdown']['headline']);
        }
    }
    if (!empty($event['series_sessions']) && is_array($event['series_sessions'])) {
        foreach ($event['series_sessions'] as &$ss) {
            if (isset($ss['title']) && $ss['title'] !== '') {
                $ss['title'] = Utilities::decodeHtmlEntities($ss['title']);
            }
        }
        unset($ss);
    }
    if (!empty($event['questions']) && is_array($event['questions'])) {
        foreach ($event['questions'] as &$q) {
            if (isset($q['question_text']) && $q['question_text'] !== '') {
                $q['question_text'] = Utilities::decodeHtmlEntities($q['question_text']);
            }
            if (!empty($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as &$o) {
                    if (isset($o['option_label']) && $o['option_label'] !== '') {
                        $o['option_label'] = Utilities::decodeHtmlEntities($o['option_label']);
                    }
                }
                unset($o);
            }
        }
        unset($q);
    }
    foreach (['speakers', 'organisers'] as $pk) {
        if (!empty($event[$pk]) && is_array($event[$pk])) {
            foreach ($event[$pk] as &$person) {
                if (isset($person['display_name']) && $person['display_name'] !== '') {
                    $person['display_name'] = Utilities::decodeHtmlEntities($person['display_name']);
                }
                if (isset($person['title']) && $person['title'] !== '' && $person['title'] !== null) {
                    $person['title'] = Utilities::decodeHtmlEntities($person['title']);
                }
            }
            unset($person);
        }
    }
}

/**
 * @param array<string,mixed> $event
 */
function portal_attach_event_people_for_api(EventPeopleService $svc, array &$event): void
{
    if (!$svc->tableExists()) {
        $event['speakers'] = [];
        $event['organisers'] = [];

        return;
    }
    $srcId = EventPeopleService::peopleStorageEventId($event);
    $rows = $svc->listForEventId($srcId);
    $speakers = [];
    $organisers = [];
    foreach ($rows as $r) {
        $path = isset($r['image_path']) ? trim((string) $r['image_path']) : '';
        $imageUrl = null;
        if ($path !== '') {
            $imageUrl = filter_var($path, FILTER_VALIDATE_URL) ? $path : hc_public_api_image_url($path);
        }
        $item = [
            'display_name' => $r['display_name'],
            'title' => $r['title'] ?? null,
            'image_url' => $imageUrl,
        ];
        if (($r['role'] ?? '') === 'organiser') {
            $organisers[] = $item;
        } else {
            $speakers[] = $item;
        }
    }
    $event['speakers'] = $speakers;
    $event['organisers'] = $organisers;
}

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

// Set JSON header
header('Content-Type: application/json');

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract event ID from path if present
$pathSegments = explode('/', trim($path, '/'));
$eventId = null;
if (count($pathSegments) >= 3 && is_numeric($pathSegments[count($pathSegments) - 1])) {
    $eventId = (int)$pathSegments[count($pathSegments) - 1];
}

$db = Database::getInstance();

try {
    // GET /api/portal/events/{id} - Get single event
    if ($eventId && $method === 'GET') {
        $event = $db->queryOne(
            "SELECT e.*, 
                    (SELECT COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) FROM rsvps WHERE event_id = e.id AND status = 'yes') as rsvp_count
             FROM events e
             WHERE e.id = :id AND e.status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit;
        }

        $portalMemberIdSingle = null;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['portal_user_id'])) {
            $portalMemberIdSingle = (int) $_SESSION['portal_user_id'];
        }
        if (!EventVisibilityService::portalMemberMayViewPublishedEvent($db, $event, $portalMemberIdSingle)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'This event is not available in the member portal. It may be staff-only (internal) or limited to invited members.',
                'error_code' => 'event_not_in_portal',
            ]);
            exit;
        }

        $event = EventSeriesHelper::mergeSeriesParentPolicyFields($db, $event);

        /** @var int Public potluck list is always for this published event row — never aggregate series parent. */
        $potluckListEventId = (int) ($event['id'] ?? $eventId);

        // Calculate available spots
        $availableSpots = null;
        if (!empty($event['capacity'])) {
            $availableSpots = max(0, $event['capacity'] - (int)$event['rsvp_count']);
        }

        $event['available_spots'] = $availableSpots;
        $event['is_full'] = !empty($event['capacity']) && $availableSpots === 0;

        $rsvpSvc = new RSVPService();
        $event['registration_closed_online'] = $rsvpSvc->isRegistrationDeadlinePassed($event);

        $eligibilitySvc = new EventEligibilityService($db);
        $event['restriction'] = [
            'enabled' => $eligibilitySvc->eventHasRestrictionRules($event),
            'min_age' => isset($event['min_age']) && $event['min_age'] !== '' ? (int) $event['min_age'] : null,
            'max_age' => isset($event['max_age']) && $event['max_age'] !== '' ? (int) $event['max_age'] : null,
            'gender_restriction' => isset($event['gender_restriction']) ? (string) $event['gender_restriction'] : 'none',
            'enforce_at_checkin' => !empty($event['enforce_restrictions_at_checkin']),
        ];
        $event['eligibility'] = null;
        $mid = PortalAuthMiddleware::getMemberId();
        if ($mid) {
            $uRow = $db->queryOne(
                'SELECT id, first_name, last_name, date_of_birth, gender FROM users WHERE id = ? AND status != ?',
                [(int) $mid, 'deleted']
            );
            if (is_array($uRow)) {
                $chk = $eligibilitySvc->checkEligibility($event, $uRow, null);
                $event['eligibility'] = [
                    'ok' => $chk['ok'],
                    'message' => $chk['message'],
                ];
            }
        }

        if (!empty($event['headcount_pricing_tiers']) && is_string($event['headcount_pricing_tiers'])) {
            $decodedTiers = json_decode($event['headcount_pricing_tiers'], true);
            $event['headcount_pricing_tiers'] = is_array($decodedTiers) ? $decodedTiers : [];
        } elseif (empty($event['headcount_pricing_tiers']) || !is_array($event['headcount_pricing_tiers'])) {
            $event['headcount_pricing_tiers'] = [];
        }
        if (empty($event['pricing_model'])) {
            $event['pricing_model'] = 'per_person';
        }

        // Mark if event is part of a recurring series (parent or instance)
        $event['is_recurring'] = false;
        try {
            if (!empty($event['parent_event_id'])) {
                $event['is_recurring'] = true;
            } else {
                $recur = $db->queryOne("SELECT 1 FROM recurring_events WHERE parent_event_id = :id LIMIT 1", ['id' => $event['id']]);
                $event['is_recurring'] = (bool) $recur;
            }
        } catch (\Exception $e) {
            // recurring_events table may not exist
        }

        // Format event date with organization timezone (same as list) so details page shows correct date
        $timezone = OrgTimeZone::FALLBACK_IANA;
        if (!empty($event['organization_id'])) {
            $org = $db->queryOne("SELECT timezone FROM organizations WHERE id = :id", ['id' => $event['organization_id']]);
            $timezone = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);
        }
        try {
            $tz = new \DateTimeZone($timezone);
            $eventDate = new \DateTime($event['event_date'] . ($event['start_time'] ? ' ' . $event['start_time'] : ' 00:00:00'), $tz);
            $event['event_date_formatted'] = $eventDate->format('Y-m-d');
            $event['event_datetime_iso'] = $eventDate->format('c');
            $event['timezone'] = $timezone;
        } catch (\Exception $e) {
            $event['event_date_formatted'] = $event['event_date'];
            $event['event_datetime_iso'] = $event['event_date'] . 'T00:00:00';
            $event['timezone'] = $timezone;
        }
        
        $bannerPath = portal_resolve_event_banner_path($db, $event, []);
        if ($bannerPath !== '') {
            if (filter_var($bannerPath, FILTER_VALIDATE_URL)) {
                $event['banner_image_url'] = $bannerPath;
            } else {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/portal/events.php';
                $basePath = dirname(dirname($scriptName));
                $basePath = dirname($basePath);
                $basePath = str_replace('\\', '/', $basePath);
                $basePath = rtrim($basePath, '/');
                $imagePath = ltrim($bannerPath, '/');
                $event['banner_image_url'] = $protocol . '://' . $host . $basePath . '/api/image.php?path=' . urlencode($imagePath);
            }
        } else {
            $event['banner_image_url'] = null;
        }
        
        // Get actual category names from categories table
        try {
            $categories = $db->query(
                "SELECT c.name, c.slug, c.color 
                 FROM categories c
                 INNER JOIN event_categories ec ON c.id = ec.category_id
                 WHERE ec.event_id = :event_id AND c.is_active = 1
                 ORDER BY c.sort_order ASC, c.name ASC",
                ['event_id' => $eventId]
            );
            
            if (!empty($categories)) {
                // Use the first category name as the primary category (for backward compatibility)
                $event['category'] = $categories[0]['name'];
                $event['category_slug'] = $categories[0]['slug'];
                $event['category_color'] = $categories[0]['color'];
                // Also include all categories
                $event['categories'] = $categories;
            } else {
                // Fallback to legacy category field if no categories found
                $event['category'] = $event['category'] ?? 'Event';
                $event['categories'] = [];
            }
        } catch (\Exception $e) {
            // If event_categories table doesn't exist, use legacy category field
            $event['category'] = $event['category'] ?? 'Event';
            $event['categories'] = [];
        }
        
        // Load event custom questions with options and conditionals (for RSVP form)
        try {
            $event['questions'] = $db->query(
                "SELECT id, question_text, question_type, is_required, sort_order, 
                        depends_on_question_id, depends_on_value 
                 FROM event_questions 
                 WHERE event_id = :event_id 
                 ORDER BY sort_order ASC, id ASC",
                ['event_id' => $eventId]
            );
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
                    // event_question_options may not exist
                }
            }
            foreach ($event['questions'] as &$q) {
                $q['options'] = $optionsByQ[$q['id']] ?? [];
                $q['depends_on_question_id'] = isset($q['depends_on_question_id']) && $q['depends_on_question_id'] !== null ? (int)$q['depends_on_question_id'] : null;
            }
            unset($q);
        } catch (\Exception $e) {
            $event['questions'] = [];
        }

        // Load event ticket types (for multiple ticket prices); hide types outside sale window
        try {
            $ttExtra = $db->hasColumn('event_ticket_types', 'sale_starts_at')
                ? ', sale_starts_at, sale_ends_at, package_group'
                : '';
            $rawTicketTypes = $db->query(
                "SELECT id, event_id, name, price, quantity_limit, sort_order{$ttExtra}
                 FROM event_ticket_types 
                 WHERE event_id = :event_id 
                 ORDER BY sort_order ASC, id ASC",
                ['event_id' => $eventId]
            );
            if (!is_array($rawTicketTypes)) {
                $rawTicketTypes = [];
            }
            $event['ticket_sale_countdown'] = $ttExtra !== ''
                ? EventTicketTypeRulesService::buildPortalSaleCountdown($rawTicketTypes, null, $timezone)
                : null;
            if ($ttExtra !== '') {
                $event['ticket_types'] = EventTicketTypeRulesService::filterRowsForPublic($rawTicketTypes, null, $timezone);
            } else {
                $event['ticket_types'] = $rawTicketTypes;
            }
        } catch (\Exception $e) {
            $event['ticket_types'] = [];
            $event['ticket_sale_countdown'] = null;
        }

        $event['potluck_category_options'] = [];
        $event['potluck_signups'] = [];
        try {
            if ($db->hasColumn('events', 'is_potluck')) {
                $potluckAllowedForEvent = PotluckCategoryService::parsePotluckAllowedSlugsFromEvent($event);
                $event['potluck_category_options'] = PotluckCategoryService::optionsForApiFiltered($potluckAllowedForEvent);
                if (!empty($event['is_potluck']) && $db->hasColumn('rsvps', 'potluck_category')) {
                    $potSelect = 'r.potluck_category, r.potluck_item_note, u.first_name AS potluck_first_name, u.last_name AS potluck_last_name';
                    foreach (['potluck_quantity', 'potluck_serving_side', 'potluck_party_adults', 'potluck_party_children'] as $potCol) {
                        if ($db->hasColumn('rsvps', $potCol)) {
                            $potSelect .= ', r.' . $potCol;
                        }
                    }
                    // One row per user per event (dedupe accidental duplicate rsvps); scope strictly to this session's event id.
                    $potRows = $db->query(
                        "SELECT {$potSelect}
                         FROM rsvps r
                         INNER JOIN (
                             SELECT user_id, MIN(id) AS keep_id
                             FROM rsvps
                             WHERE event_id = ? AND status = 'yes'
                               AND potluck_category IS NOT NULL AND TRIM(potluck_category) <> ''
                             GROUP BY user_id
                         ) uniq ON uniq.keep_id = r.id
                         INNER JOIN users u ON u.id = r.user_id
                         INNER JOIN events ev ON ev.id = r.event_id AND ev.id = ? AND ev.status = 'published'",
                        [$potluckListEventId, $potluckListEventId]
                    );
                    $signups = [];
                    foreach ($potRows as $pr) {
                        $slug = trim((string) ($pr['potluck_category'] ?? ''));
                        if ($slug === '') {
                            continue;
                        }
                        $fn = isset($pr['potluck_first_name']) ? trim((string) $pr['potluck_first_name']) : '';
                        $ln = isset($pr['potluck_last_name']) ? trim((string) $pr['potluck_last_name']) : '';
                        $displayName = trim($fn . ' ' . $ln);
                        if ($displayName === '') {
                            $displayName = 'Community member';
                        }
                        $label = PotluckCategoryService::labelForSlug($slug) ?? $slug;
                        $note = isset($pr['potluck_item_note']) ? trim((string) $pr['potluck_item_note']) : '';
                        $sideRaw = isset($pr['potluck_serving_side']) ? trim((string) $pr['potluck_serving_side']) : '';
                        $row = [
                            'category_id' => $slug,
                            'category_label' => $label,
                            'item_note' => $note !== '' ? $note : null,
                            'contributor_name' => $displayName,
                        ];
                        if (isset($pr['potluck_quantity'])) {
                            $row['quantity'] = (int) $pr['potluck_quantity'];
                        }
                        if ($sideRaw !== '') {
                            $row['serving_side'] = $sideRaw;
                            $row['serving_side_label'] = PotluckCategoryService::labelForServingSide($sideRaw) ?? $sideRaw;
                        }
                        if (isset($pr['potluck_party_adults'])) {
                            $row['party_adults'] = (int) $pr['potluck_party_adults'];
                        }
                        if (isset($pr['potluck_party_children'])) {
                            $row['party_children'] = (int) $pr['potluck_party_children'];
                        }
                        $signups[] = $row;
                    }
                    usort($signups, static function (array $a, array $b): int {
                        $cmp = PotluckCategoryService::sortOrderIndex($a['category_id'])
                            <=> PotluckCategoryService::sortOrderIndex($b['category_id']);
                        if ($cmp !== 0) {
                            return $cmp;
                        }
                        $cmpN = strcmp((string) ($a['contributor_name'] ?? ''), (string) ($b['contributor_name'] ?? ''));
                        if ($cmpN !== 0) {
                            return $cmpN;
                        }
                        return strcmp((string) ($a['item_note'] ?? ''), (string) ($b['item_note'] ?? ''));
                    });
                    $event['potluck_signups'] = $signups;
                }
            } else {
                $event['is_potluck'] = false;
            }
        } catch (\Exception $e) {
            $event['potluck_category_options'] = [];
            $event['potluck_signups'] = [];
        }
        
        // Check if user has RSVP'd (if authenticated)
        $event['user_rsvp'] = null;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $portalUserId = null;
        if (isset($_SESSION['portal_user_id'])) {
            $portalUserId = (int) $_SESSION['portal_user_id'];
            $userRsvp = $db->queryOne(
                "SELECT * FROM rsvps WHERE event_id = :event_id AND user_id = :user_id",
                ['event_id' => $eventId, 'user_id' => $portalUserId]
            );
            if ($userRsvp) {
                $pcSlug = isset($userRsvp['potluck_category']) ? trim((string) $userRsvp['potluck_category']) : '';
                $event['user_rsvp'] = [
                    'id' => $userRsvp['id'],
                    'status' => $userRsvp['status'],
                    'created_at' => $userRsvp['created_at'],
                    'guest_count' => isset($userRsvp['guest_count']) ? (int)$userRsvp['guest_count'] : 0,
                    'potluck_category' => $pcSlug !== '' ? $pcSlug : null,
                    'potluck_item_note' => isset($userRsvp['potluck_item_note']) ? (string) $userRsvp['potluck_item_note'] : null,
                ];
                if ($pcSlug !== '') {
                    $event['user_rsvp']['potluck_category_label'] = PotluckCategoryService::labelForSlug($pcSlug) ?? $pcSlug;
                }
                if ($db->hasColumn('rsvps', 'potluck_quantity')) {
                    $event['user_rsvp']['potluck_quantity'] = isset($userRsvp['potluck_quantity']) ? (int) $userRsvp['potluck_quantity'] : null;
                }
                if ($db->hasColumn('rsvps', 'potluck_serving_side')) {
                    $event['user_rsvp']['potluck_serving_side'] = isset($userRsvp['potluck_serving_side']) ? (string) $userRsvp['potluck_serving_side'] : null;
                }
                if ($db->hasColumn('rsvps', 'potluck_party_adults')) {
                    $event['user_rsvp']['potluck_party_adults'] = isset($userRsvp['potluck_party_adults']) ? (int) $userRsvp['potluck_party_adults'] : null;
                }
                if ($db->hasColumn('rsvps', 'potluck_party_children')) {
                    $event['user_rsvp']['potluck_party_children'] = isset($userRsvp['potluck_party_children']) ? (int) $userRsvp['potluck_party_children'] : null;
                }
            }
        }

        $event['session_registration_mode'] = EventSeriesHelper::getSessionRegistrationMode($db, $eventId);
        $event['series_root_id'] = EventSeriesHelper::getSeriesRootId($db, $eventId);
        $seriesIds = [];
        if ($event['series_root_id']) {
            $seriesIds = headcount_published_series_event_ids($db, (int) $event['series_root_id']);
        }
        $event['series_sessions'] = [];
        $event['user_registered_session_ids'] = [];
        $event['portal_series_state'] = 'none';

        $multiSession = count($seriesIds) > 1;
        $mode = $event['session_registration_mode'];
        if ($multiSession && $portalUserId) {
            $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
            $yesRows = $db->query(
                "SELECT event_id FROM rsvps WHERE user_id = ? AND status = 'yes' AND event_id IN ($placeholders)",
                array_merge([$portalUserId], $seriesIds)
            );
            $event['user_registered_session_ids'] = array_map('intval', array_column($yesRows, 'event_id'));
        }

        // All published sessions in the series (for RSVP rules UI: pick-one, all-sessions, or per-session independent)
        if ($multiSession) {
            foreach ($seriesIds as $sid) {
                $s = $db->queryOne(
                    "SELECT id, title, event_date, start_time, end_time, capacity FROM events WHERE id = :id AND status = 'published'",
                    ['id' => $sid]
                );
                if (!$s) {
                    continue;
                }
                $rc = null;
                $spots = null;
                $full = false;
                if (!empty($s['capacity'])) {
                    $cntRow = $db->queryOne(
                        "SELECT COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) as total FROM rsvps WHERE event_id = :eid AND status = 'yes'",
                        ['eid' => $sid]
                    );
                    $rc = (int) ($cntRow['total'] ?? 0);
                    $spots = max(0, (int) $s['capacity'] - $rc);
                    $full = $spots === 0;
                }
                $dateFmt = $s['event_date'];
                try {
                    $tz = new \DateTimeZone($timezone);
                    $d = new \DateTime($s['event_date'] . ($s['start_time'] ? ' ' . $s['start_time'] : ' 00:00:00'), $tz);
                    $dateFmt = $d->format('Y-m-d');
                } catch (\Exception $e) {
                }
                $event['series_sessions'][] = [
                    'id' => (int) $s['id'],
                    'title' => $s['title'],
                    'event_date' => $s['event_date'],
                    'event_date_formatted' => $dateFmt,
                    'start_time' => $s['start_time'],
                    'end_time' => $s['end_time'],
                    'is_full' => $full,
                    'available_spots' => $spots
                ];
            }
        }

        if (!$multiSession || $mode === EventSeriesHelper::MODE_INDEPENDENT) {
            if (!empty($event['user_rsvp']) && $event['user_rsvp']['status'] === 'yes') {
                $event['portal_series_state'] = 'going';
            }
        } elseif ($mode === EventSeriesHelper::MODE_ALL_SESSIONS) {
            $yesSet = $event['user_registered_session_ids'];
            if (count($yesSet) >= count($seriesIds) && count(array_diff($seriesIds, $yesSet)) === 0) {
                $event['portal_series_state'] = 'going';
            } elseif (count($yesSet) > 0) {
                $event['portal_series_state'] = 'partial';
            }
        } elseif ($mode === EventSeriesHelper::MODE_CHOOSE_ONE) {
            if (in_array($eventId, $event['user_registered_session_ids'], true)) {
                $event['portal_series_state'] = 'going';
            } elseif (count($event['user_registered_session_ids']) > 0) {
                $event['portal_series_state'] = 'going_other';
            }
        }

        $portalEventPeopleSvc = new EventPeopleService();
        portal_attach_event_people_for_api($portalEventPeopleSvc, $event);

        // Normalise DB tinyint / string flags for JSON (clients must not rely on "0" string truthiness).
        try {
            if ($db->hasColumn('events', 'allow_guest_rsvp')) {
                $event['allow_guest_rsvp'] = !empty($event['allow_guest_rsvp']);
            } else {
                $event['allow_guest_rsvp'] = false;
            }
            if ($db->hasColumn('events', 'allow_bring_guests')) {
                $event['allow_bring_guests'] = !empty($event['allow_bring_guests']);
            } else {
                $event['allow_bring_guests'] = false;
            }
            if ($db->hasColumn('events', 'is_potluck')) {
                $event['is_potluck'] = !empty($event['is_potluck']);
            }
            if ($db->hasColumn('events', 'potluck_show_bringing_prompt')) {
                $event['potluck_show_bringing_prompt'] = !empty($event['potluck_show_bringing_prompt']);
            } else {
                $event['potluck_show_bringing_prompt'] = true;
            }
        } catch (\Throwable $e) {
            $event['allow_guest_rsvp'] = false;
            $event['allow_bring_guests'] = false;
            $event['potluck_show_bringing_prompt'] = true;
        }
        $event['guest_rsvp_portal_allowed'] = EventVisibilityService::guestRsvpAllowed($event);

        try {
            $orgWaiver = $db->queryOne(
                'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
                ['id' => (int) ($event['organization_id'] ?? 0)]
            );
            $event['waiver'] = headcount_portal_waiver_payload(is_array($orgWaiver) ? $orgWaiver : null);
        } catch (\Throwable $e) {
            $event['waiver'] = headcount_portal_waiver_payload(null);
        }

        portal_apply_event_text_decode($event);

        echo json_encode([
            'success' => true,
            'event' => $event
        ]);
        exit;
    }

    // GET /api/portal/events - List events
    if ($method === 'GET') {
        // Start session if needed
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Get organization ID from logged-in user, query parameter, or single-tenant/config fallback
        $organizationId = null;
        if (PortalAuthMiddleware::isAuthenticated()) {
            $organizationId = PortalAuthMiddleware::getOrganizationId();
        } else {
            $rawOrg = $_GET['organization_id'] ?? null;
            $organizationId = ($rawOrg !== null && $rawOrg !== '') ? (int) $rawOrg : null;
            if (!$organizationId) {
                $organizationId = headcount_resolve_portal_organization_id(null, $config, $db);
            }
        }
        
        // Get organization timezone for date handling (falls back to OrgTimeZone::FALLBACK_IANA)
        $timezone = OrgTimeZone::FALLBACK_IANA;
        if ($organizationId) {
            $org = $db->queryOne("SELECT timezone FROM organizations WHERE id = :id", ['id' => $organizationId]);
            $timezone = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);
        }
        
        // Get filters
        $category = $_GET['category'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $search = $_GET['search'] ?? null;

        // Build query
        $sql = "SELECT e.*, 
                       (SELECT COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) FROM rsvps WHERE event_id = e.id AND status = 'yes') as rsvp_count
                FROM events e
                WHERE e.status = 'published'";

        // Positional placeholders — safe with native PDO (named placeholders cannot be reused)
        $params = [];

        if ($organizationId) {
            $sql .= ' AND e.organization_id = ?';
            $params[] = $organizationId;
        }

        if ($category) {
            $sql .= ' AND e.category = ?';
            $params[] = $category;
        }

        if ($dateFrom) {
            $sql .= ' AND e.event_date >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $sql .= ' AND e.event_date <= ?';
            $params[] = $dateTo;
        }

        if ($search) {
            $searchLike = '%' . $search . '%';
            $sql .= ' AND (e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)';
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
        }

        // Only show future events or today's events (organization timezone)
        try {
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTime('now', $tz);
            $today = $now->format('Y-m-d');
            $currentTime = $now->format('H:i:s');

            $sql .= ' AND (e.event_date > ? OR (e.event_date = ? AND (e.end_time IS NULL OR e.end_time > ?)))';
            $params[] = $today;
            $params[] = $today;
            $params[] = $currentTime;
        } catch (\Exception $e) {
            $sql .= ' AND (e.event_date > CURDATE() OR (e.event_date = CURDATE() AND (e.end_time IS NULL OR e.end_time > CURTIME())))';
        }

        // List can include both series parents and instances; we dedupe below to one row per series
        // (next upcoming session) so portal users are not flooded with duplicate tiles.

        $sql .= " ORDER BY e.event_date ASC, e.start_time ASC";

        $events = $db->query($sql, $params);

        $hasVisibilityCol = false;
        try {
            $hasVisibilityCol = $db->hasColumn('events', 'visibility');
        } catch (\Throwable $e) {
            $hasVisibilityCol = false;
        }
        if ($hasVisibilityCol && is_array($events)) {
            $portalUidList = null;
            if (isset($_SESSION['portal_user_id'])) {
                $portalUidList = (int) $_SESSION['portal_user_id'];
            }
            $events = array_values(array_filter($events, static function (array $ev) use ($db, $portalUidList): bool {
                return EventVisibilityService::portalMemberMayViewPublishedEvent($db, $ev, $portalUidList);
            }));
        }

        // One card per recurring series: keep earliest upcoming row per COALESCE(parent_event_id, id).
        $seriesCompare = static function (array $a, array $b): int {
            $da = (string) ($a['event_date'] ?? '');
            $db = (string) ($b['event_date'] ?? '');
            if ($da !== $db) {
                return strcmp($da, $db);
            }
            $ta = (string) ($a['start_time'] ?? '');
            $tb = (string) ($b['start_time'] ?? '');
            if ($ta === '') {
                $ta = '00:00:00';
            } elseif (strlen($ta) === 5) {
                $ta .= ':00';
            }
            if ($tb === '') {
                $tb = '00:00:00';
            } elseif (strlen($tb) === 5) {
                $tb .= ':00';
            }
            $tc = strcmp($ta, $tb);
            if ($tc !== 0) {
                return $tc;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        };

        $buckets = [];
        foreach ($events as $ev) {
            $pid = isset($ev['parent_event_id']) ? (int) $ev['parent_event_id'] : 0;
            $root = $pid > 0 ? $pid : (int) ($ev['id'] ?? 0);
            if ($root <= 0) {
                continue;
            }
            $buckets[$root][] = $ev;
        }
        $deduped = [];
        foreach ($buckets as $root => $list) {
            usort($list, $seriesCompare);
            $best = $list[0];
            $best['series_root_id'] = $root;
            $best['upcoming_sessions_in_series'] = count($list);
            $deduped[] = $best;
        }
        usort($deduped, $seriesCompare);
        $events = $deduped;

        $parentIdsForBanner = [];
        foreach ($events as $ev) {
            $own = trim((string) ($ev['banner_image'] ?? ''));
            if ($own === '' && !empty($ev['parent_event_id'])) {
                $parentIdsForBanner[(int) $ev['parent_event_id']] = true;
            }
        }
        $parentBannerById = [];
        if (!empty($parentIdsForBanner)) {
            $ids = array_keys($parentIdsForBanner);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $prows = $db->query("SELECT id, banner_image FROM events WHERE id IN ($placeholders)", $ids);
            foreach ($prows as $pr) {
                $parentBannerById[(int) $pr['id']] = $pr['banner_image'] ?? null;
            }
        }

        // Resolve is_recurring for each event (parent has row in recurring_events, or event is instance)
        $recurringParentIds = [];
        try {
            $rows = $db->query("SELECT parent_event_id FROM recurring_events");
            $recurringParentIds = array_column($rows, 'parent_event_id');
        } catch (\Exception $e) {
            // recurring_events table may not exist
        }
        $recurringParentIdSet = array_fill_keys(array_map('intval', $recurringParentIds), true);
        foreach ($events as &$event) {
            $multiUpcoming = (int) ($event['upcoming_sessions_in_series'] ?? 1) > 1;
            $eid = (int) ($event['id'] ?? 0);
            $event['is_recurring'] = $multiUpcoming
                || !empty($event['parent_event_id'])
                || isset($recurringParentIdSet[$eid]);
        }
        unset($event);

        // Get user ID if authenticated
        $userId = null;
        if (isset($_SESSION['portal_user_id'])) {
            $userId = $_SESSION['portal_user_id'];
        }

        // Add available spots, full status, user RSVP status, categories, and banner images
        // Also format dates with timezone
        $tz = new \DateTimeZone($timezone);
        $rsvpSvcList = new RSVPService();
        $portalEventPeopleSvcList = new EventPeopleService();
        foreach ($events as &$event) {
            $availableSpots = null;
            if (!empty($event['capacity'])) {
                $availableSpots = max(0, $event['capacity'] - (int)$event['rsvp_count']);
            }
            $event['available_spots'] = $availableSpots;
            $event['is_full'] = !empty($event['capacity']) && $availableSpots === 0;
            
            // Format event date with timezone to prevent date shift
            // Create a date object in the organization's timezone
            try {
                $eventDate = new \DateTime($event['event_date'] . ($event['start_time'] ? ' ' . $event['start_time'] : ' 00:00:00'), $tz);
                $event['event_date_formatted'] = $eventDate->format('Y-m-d');
                $event['event_datetime_iso'] = $eventDate->format('c'); // ISO 8601 format with timezone
                $event['timezone'] = $timezone;
            } catch (\Exception $e) {
                // Fallback if date parsing fails
                $event['event_date_formatted'] = $event['event_date'];
                $event['event_datetime_iso'] = $event['event_date'] . 'T00:00:00';
                $event['timezone'] = $timezone;
            }
            
            $bannerPath = portal_resolve_event_banner_path($db, $event, $parentBannerById);
            if ($bannerPath !== '') {
                if (filter_var($bannerPath, FILTER_VALIDATE_URL)) {
                    $event['banner_image_url'] = $bannerPath;
                } else {
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseUrl = $protocol . '://' . $host;
                    $imagePath = ltrim($bannerPath, '/');
                    $event['banner_image_url'] = $baseUrl . '/api/image.php?path=' . urlencode($imagePath);
                }
            } else {
                $event['banner_image_url'] = null;
            }
            
            // Get actual category names from categories table
            try {
                $categories = $db->query(
                    "SELECT c.name, c.slug, c.color 
                     FROM categories c
                     INNER JOIN event_categories ec ON c.id = ec.category_id
                     WHERE ec.event_id = :event_id AND c.is_active = 1
                     ORDER BY c.sort_order ASC, c.name ASC",
                    ['event_id' => $event['id']]
                );
                
                if (!empty($categories)) {
                    // Use the first category name as the primary category (for backward compatibility)
                    $event['category'] = $categories[0]['name'];
                    $event['category_slug'] = $categories[0]['slug'];
                    $event['category_color'] = $categories[0]['color'];
                    // Also include all categories
                    $event['categories'] = $categories;
                } else {
                    // Fallback to legacy category field if no categories found
                    $event['category'] = $event['category'] ?? 'Event';
                    $event['categories'] = [];
                }
            } catch (\Exception $e) {
                // If event_categories table doesn't exist, use legacy category field
                $event['category'] = $event['category'] ?? 'Event';
                $event['categories'] = [];
            }
            
            // Add user RSVP status if authenticated
            $event['user_rsvp'] = null;
            if ($userId) {
                $userRsvp = $db->queryOne(
                    "SELECT * FROM rsvps WHERE event_id = :event_id AND user_id = :user_id",
                    ['event_id' => $event['id'], 'user_id' => $userId]
                );
                if ($userRsvp) {
                    $event['user_rsvp'] = [
                        'id' => $userRsvp['id'],
                        'status' => $userRsvp['status'],
                        'created_at' => $userRsvp['created_at']
                    ];
                }
            }

            $event['registration_closed_online'] = $rsvpSvcList->isRegistrationDeadlinePassed($event);
            portal_attach_event_people_for_api($portalEventPeopleSvcList, $event);
            portal_apply_event_text_decode($event);
        }

        echo json_encode([
            'success' => true,
            'events' => $events,
            'count' => count($events),
            'timezone' => $timezone
        ]);
        exit;
    }

    // 405 - Method not allowed
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("Portal events API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
