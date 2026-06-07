<?php
namespace Headcount\Core;

/**
 * Enhanced Shortcode Handlers
 * Provides comprehensive shortcodes for displaying events, RSVP forms, search, and more
 */
class Shortcodes {
    
    private $api_client;
    /** @var EventPresenter */
    private $presenter;
    
    public function __construct($api_client) {
        $this->api_client = $api_client;
        $this->presenter = new EventPresenter();
    }

    /**
     * Portal base URL for member links (program details, etc.).
     * Uses the explicit setting, or derives …/public/portal from the API URL when unset.
     */
    private function portal_base_url() {
        $portal = trim((string) get_option('headcount_portal_url', ''));
        if ($portal !== '') {
            return rtrim($portal, '/');
        }
        $api = rtrim((string) get_option('headcount_api_url', ''), '/');
        if ($api === '') {
            return '';
        }
        if (preg_match('#/public/api$#i', $api)) {
            return (string) preg_replace('#/public/api$#i', '/public/portal', $api);
        }
        // Default app layout: API at …/public/api or …/Headcount/api → portal at …/public/portal.
        if (preg_match('#/api$#i', $api)) {
            return (string) preg_replace('#/api$#i', '/public/portal', $api);
        }
        // Same folder as event-details.php (…/public/portal) when API URL is non-standard.
        $evd = trim((string) get_option('headcount_event_details_url', ''));
        if ($evd !== '' && preg_match('#^(.+)/event-details\.php#i', $evd, $m)) {
            return rtrim((string) $m[1], '/');
        }
        return '';
    }

    public function register() {
        add_shortcode('headcount_events', array($this, 'events_list_shortcode'));
        add_shortcode('headcount_event', array($this, 'single_event_shortcode'));
        add_shortcode('headcount_calendar', array($this, 'calendar_shortcode'));
        add_shortcode('headcount_rsvp', array($this, 'rsvp_shortcode'));
        add_shortcode('headcount_search', array($this, 'search_shortcode'));
        add_shortcode('headcount_categories', array($this, 'categories_shortcode'));
        add_shortcode('headcount_showcase', array($this, 'showcase_shortcode'));
        add_shortcode('headcount_programs', array($this, 'programs_list_shortcode'));
        add_shortcode('headcount_facilities', array($this, 'facilities_list_shortcode'));
        add_shortcode('headcount_facility', array($this, 'single_facility_shortcode'));
        add_shortcode('headcount_facility_calendar', array($this, 'facility_calendar_shortcode'));
    }

    /**
     * Tabs: Events | Programs
     */
    public function showcase_shortcode($atts) {
        $atts = shortcode_atts(array(
            'events_limit' => '6',
            'programs_limit' => '6',
            'facilities_limit' => '0',
            'layout' => 'grid',
        ), $atts, 'headcount_showcase');
        wp_enqueue_style('headcount-styles');

        $ev = $this->api_client->get_events(array('limit' => (int) $atts['events_limit'], 'show_past' => false));
        $events = $this->format_events($ev['events'] ?? []);

        $pr = $this->api_client->get_programs(array('limit' => (int) $atts['programs_limit']));
        $programs = array();
        if (!empty($pr['success']) && !empty($pr['programs'])) {
            foreach ($pr['programs'] as $p) {
                $programs[] = $this->presenter->apply_resolved_program_banner_urls($p);
            }
        }

        $facilities = array();
        $facilities_limit = (int) $atts['facilities_limit'];
        if ($facilities_limit > 0) {
            $facPresenter = new FacilityPresenter();
            $fr = $this->api_client->get_facilities(array('limit' => $facilities_limit));
            if (!empty($fr['success']) && !empty($fr['facilities'])) {
                $facilities = $facPresenter->format_facilities($fr['facilities']);
            }
        }

        $portal = $this->portal_base_url();
        $booking_url = trim((string) get_option('headcount_facility_booking_url', ''));
        if ($booking_url === '' && $portal !== '') {
            $booking_url = $portal;
        }

        return Renderer::render('showcase-tabs', array(
            'events' => $events,
            'programs' => $programs,
            'facilities' => $facilities,
            'atts' => $atts,
            'portal_base_url' => $portal,
            'booking_base_url' => $booking_url,
            'show_facilities_tab' => $facilities_limit > 0,
        ));
    }

    public function events_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => '10',
            'category' => '',
            'layout' => 'list', // list, grid
            'show_image' => 'true',
            'show_rsvp' => 'true',
            'show_category' => 'true',
            'show_past' => 'false',
            'sort_by' => 'date', // date, title, category
            'theme' => get_option('headcount_theme', 'light'),
            'per_page' => get_option('headcount_events_per_page', 10),
            'show_pagination' => 'true'
        ), $atts, 'headcount_events');
        
        // Get current page from URL
        $paged = isset($_GET['hc_page']) ? max(1, intval($_GET['hc_page'])) : 1;
        
        // Fetch more events than needed for pagination
        $fetch_limit = $atts['show_pagination'] === 'true' ? 100 : $atts['limit'];
        
        $response = $this->api_client->get_events(array(
            'limit' => $fetch_limit,
            'category' => $atts['category'],
            'show_past' => $atts['show_past'] === 'true'
        ));
        
        if (!isset($response['success']) || !$response['success']) {
            return Renderer::render('error', array(
                'message' => $response['message'] ?? 'Unable to load events. Please try again later.'
            ));
        }
        
        $events = $this->format_events($response['events'] ?? []);
        
        // Sort events
        $events = $this->sort_events($events, $atts['sort_by']);
        
        if (empty($events)) {
            return Renderer::render('empty', array(
                'title' => __('No upcoming events', 'headcount'),
                'message' => __('There are no events to show yet. Check back soon for new dates.', 'headcount'),
            ));
        }
        
        // Pagination
        $per_page = intval($atts['per_page']);
        $total_events = count($events);
        $total_pages = ceil($total_events / $per_page);
        
        // Slice events for current page
        if ($atts['show_pagination'] === 'true') {
            $offset = ($paged - 1) * $per_page;
            $events = array_slice($events, $offset, $per_page);
        }

        $template = ($atts['layout'] === 'grid') ? 'events-grid' : 'events-list';
        
        return Renderer::render($template, array(
            'events' => $events,
            'atts' => $atts,
            'pagination' => array(
                'current_page' => $paged,
                'total_pages' => $total_pages,
                'total_events' => $total_events,
                'per_page' => $per_page,
                'show' => $atts['show_pagination'] === 'true' && $total_pages > 1
            )
        ));
    }

    /**
     * Public programs list (grid), same data source as showcase programs tab.
     */
    public function programs_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => '12',
        ), $atts, 'headcount_programs');
        wp_enqueue_style('headcount-styles');

        $pr = $this->api_client->get_programs(array('limit' => (int) $atts['limit']));
        $programs = array();
        if (!empty($pr['success']) && !empty($pr['programs'])) {
            foreach ($pr['programs'] as $p) {
                $programs[] = $this->presenter->apply_resolved_program_banner_urls($p);
            }
        }

        $portal = $this->portal_base_url();

        if (empty($programs)) {
            return Renderer::render('empty', array(
                'title' => __('No programs', 'headcount'),
                'message' => __('There are no programs to show yet.', 'headcount'),
            ));
        }

        return Renderer::render('programs-grid', array(
            'programs' => $programs,
            'atts' => $atts,
            'portal_base_url' => $portal,
        ));
    }

    public function single_event_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'show_rsvp' => 'true',
            'theme' => get_option('headcount_theme', 'light')
        ), $atts, 'headcount_event');
        
        if (empty($atts['id'])) {
            return '<div class="headcount-error">Event ID required.</div>';
        }
        
        $response = $this->api_client->get_event($atts['id']);
        
        if (!isset($response['success']) || !$response['success']) {
            return Renderer::render('error', array(
                'message' => 'Event not found.'
            ));
        }
        
        $events = $this->format_events([$response['event']]);
        
        return Renderer::render('event-single', array(
            'event' => $events[0],
            'atts' => $atts
        ));
    }

    public function calendar_shortcode($atts) {
        $atts = shortcode_atts(array(
            'view' => 'month',
            'height' => '600px',
            'theme' => get_option('headcount_theme', 'light')
        ), $atts, 'headcount_calendar');

        wp_enqueue_style('headcount-styles');

        $combined = array();
        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $feed = $this->api_client->get_calendar_feed(array(
            'start' => gmdate('Y-m-d', strtotime('-12 months', $now)),
            'end' => gmdate('Y-m-d', strtotime('+18 months', $now)),
        ));
        if (!empty($feed['success']) && !empty($feed['items']) && is_array($feed['items'])) {
            foreach ($feed['items'] as $it) {
                $startRaw = isset($it['start']) ? (string) $it['start'] : '';
                $ts = $startRaw !== '' ? strtotime($startRaw) : false;
                if ($ts === false) {
                    continue;
                }
                $endRaw = isset($it['end']) ? (string) $it['end'] : '';
                $te = $endRaw !== '' ? strtotime($endRaw) : $ts;
                if ($te === false) {
                    $te = $ts;
                }
                $event_date = '';
                if ($startRaw !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $startRaw, $dm)) {
                    $event_date = $dm[1];
                } else {
                    $event_date = function_exists('wp_date') ? wp_date('Y-m-d', $ts) : gmdate('Y-m-d', $ts);
                }
                $prefix = (!empty($it['type']) && $it['type'] === 'program') ? 'p_' : 'e_';
                $rowId = $prefix . (int) ($it['id'] ?? 0);
                if (!empty($it['type']) && $it['type'] === 'program' && !empty($it['session_id'])) {
                    $rowId .= '_' . (int) $it['session_id'];
                }
                $combined[] = array(
                    'id' => $rowId,
                    'title' => $it['title'] ?? '',
                    'event_date' => $event_date,
                    'start_time' => function_exists('wp_date') ? wp_date('H:i:s', $ts) : gmdate('H:i:s', $ts),
                    'end_time' => function_exists('wp_date') ? wp_date('H:i:s', $te) : gmdate('H:i:s', $te),
                    'location' => '',
                    'category' => (!empty($it['type']) && $it['type'] === 'program') ? __('Program', 'headcount') : ($it['category'] ?? ''),
                    'description' => '',
                    'banner_image' => $it['banner_image'] ?? '',
                    'ticket_price' => 0,
                    'attendance_count' => 0,
                    'calendar_item_type' => $it['type'] ?? 'event',
                );
            }
        }
        if (empty($combined)) {
            $response = $this->api_client->get_events(array('limit' => 100));
            $combined = $response['events'] ?? array();
        }

        $events = $this->format_events($combined);

        $calendar_instance_id = function_exists('wp_unique_id')
            ? wp_unique_id('hc-calendar-')
            : 'hc-calendar-' . uniqid('', false);

        $portal = $this->portal_base_url();

        return Renderer::render('event-calendar', array(
            'events' => $events,
            'atts' => $atts,
            'event_details_url' => get_option('headcount_event_details_url', ''),
            'portal_base_url' => $portal,
            'calendar_instance_id' => $calendar_instance_id,
        ));
    }
    
    /**
     * RSVP Form Shortcode
     */
    public function rsvp_shortcode($atts) {
        $atts = shortcode_atts(array(
            'event_id' => '',
            'theme' => get_option('headcount_theme', 'light')
        ), $atts, 'headcount_rsvp');
        
        if (empty($atts['event_id'])) {
            return '<div class="headcount-error">Event ID required for RSVP form.</div>';
        }
        
        // Handle RSVP submission
        if (isset($_POST['headcount_rsvp_submit']) && wp_verify_nonce($_POST['headcount_rsvp_nonce'], 'headcount_rsvp_' . $atts['event_id'])) {
            $result = $this->handle_rsvp_submission($atts['event_id']);
            
            if ($result['success']) {
                return '<div class="headcount-success">' . esc_html($result['message']) . '</div>';
            } else {
                $error_message = $result['message'];
            }
        }
        
        return Renderer::render('event-rsvp-form', array(
            'event_id' => $atts['event_id'],
            'error' => $error_message ?? '',
            'atts' => $atts
        ));
    }
    
    /**
     * Search Widget Shortcode
     */
    public function search_shortcode($atts) {
        $atts = shortcode_atts(array(
            'placeholder' => 'Search events...',
            'show_filters' => 'true',
            'theme' => get_option('headcount_theme', 'light')
        ), $atts, 'headcount_search');
        
        return Renderer::render('event-search', array(
            'atts' => $atts
        ));
    }
    
    /**
     * Categories Filter Shortcode
     */
    public function categories_shortcode($atts) {
        $atts = shortcode_atts(array(
            'show_all' => 'true',
            'theme' => get_option('headcount_theme', 'light')
        ), $atts, 'headcount_categories');
        
        $response = $this->api_client->get_categories();
        $categories = $response['categories'] ?? [];
        
        return Renderer::render('event-categories', array(
            'categories' => $categories,
            'atts' => $atts
        ));
    }
    
    /**
     * Handle RSVP form submission
     */
    private function handle_rsvp_submission($event_id) {
        $data = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'guests' => intval($_POST['guests'] ?? 1),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? '')
        );
        
        // Validate
        if (empty($data['name']) || empty($data['email'])) {
            return array(
                'success' => false,
                'message' => 'Name and email are required.'
            );
        }
        
        if (!is_email($data['email'])) {
            return array(
                'success' => false,
                'message' => 'Please enter a valid email address.'
            );
        }
        
        // Submit to API
        $response = $this->api_client->submit_rsvp($event_id, $data);
        
        if (isset($response['success']) && $response['success']) {
            return array(
                'success' => true,
                'message' => 'Thank you for your RSVP! We look forward to seeing you.'
            );
        }
        
        return array(
            'success' => false,
            'message' => $response['message'] ?? 'Unable to submit RSVP. Please try again.'
        );
    }
    
    /**
     * Sort events by specified field
     */
    private function sort_events($events, $sort_by) {
        switch ($sort_by) {
            case 'title':
                usort($events, function($a, $b) {
                    return strcmp($a['title'], $b['title']);
                });
                break;
            case 'category':
                usort($events, function($a, $b) {
                    return strcmp($a['category'] ?? '', $b['category'] ?? '');
                });
                break;
            case 'date':
            default:
                // Already sorted by date from API
                break;
        }
        
        return $events;
    }

    /**
     * Format events for display
     *
     * @param array[] $events
     * @return array[]
     */
    private function format_events($events) {
        return $this->presenter->format_events($events);
    }

    /**
     * Facilities grid shortcode
     */
    public function facilities_list_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => '50',
            'layout' => 'grid',
        ), $atts, 'headcount_facilities');
        wp_enqueue_style('headcount-styles');

        $facPresenter = new FacilityPresenter();
        $response = $this->api_client->get_facilities(array('limit' => (int) $atts['limit']));
        $facilities = array();
        if (!empty($response['success']) && !empty($response['facilities'])) {
            $facilities = $facPresenter->format_facilities($response['facilities']);
        }

        if (empty($facilities)) {
            return Renderer::render('empty', array(
                'title' => __('No facilities', 'headcount'),
                'message' => __('There are no bookable facilities to show yet.', 'headcount'),
            ));
        }

        $portal = $this->portal_base_url();
        $booking_url = trim((string) get_option('headcount_facility_booking_url', ''));
        if ($booking_url === '' && $portal !== '') {
            $booking_url = $portal;
        }

        return Renderer::render('facilities-grid', array(
            'facilities' => $facilities,
            'atts' => $atts,
            'portal_base_url' => $portal,
            'booking_base_url' => $booking_url,
        ));
    }

    /**
     * Single facility by id or slug.
     */
    public function single_facility_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'slug' => '',
        ), $atts, 'headcount_facility');

        if ($atts['id'] === '' && $atts['slug'] === '') {
            return '<div class="headcount-error">' . esc_html__('Facility id or slug required.', 'headcount') . '</div>';
        }

        wp_enqueue_style('headcount-styles');

        $query = array();
        if ($atts['id'] !== '') {
            $query['id'] = (int) $atts['id'];
        } else {
            $query['slug'] = $atts['slug'];
        }

        $response = $this->api_client->get_facility($query);
        if (empty($response['success']) || empty($response['facility'])) {
            return Renderer::render('error', array(
                'message' => $response['message'] ?? __('Facility not found.', 'headcount'),
            ));
        }

        $facPresenter = new FacilityPresenter();
        $facility = $facPresenter->format_facility($response['facility']);

        $portal = $this->portal_base_url();
        $booking_url = trim((string) get_option('headcount_facility_booking_url', ''));
        if ($booking_url === '' && $portal !== '') {
            $booking_url = $portal;
        }

        return Renderer::render('facility-single', array(
            'facility' => $facility,
            'atts' => $atts,
            'portal_base_url' => $portal,
            'booking_base_url' => $booking_url,
        ));
    }

    /**
     * Facility availability calendar
     */
    public function facility_calendar_shortcode($atts) {
        $atts = shortcode_atts(array(
            'facility' => '',
            'height' => '500px',
        ), $atts, 'headcount_facility_calendar');

        if ($atts['facility'] === '') {
            return '<div class="headcount-error">' . esc_html__('Facility slug required.', 'headcount') . '</div>';
        }

        wp_enqueue_style('headcount-styles');

        $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
        $feed = $this->api_client->get_facility_availability($atts['facility'], array(
            'start' => gmdate('Y-m-01', $now),
            'end' => gmdate('Y-m-t', strtotime('+3 months', $now)),
        ));

        $blocks = array();
        if (!empty($feed['success']) && !empty($feed['blocks'])) {
            $blocks = $feed['blocks'];
        }

        $portal = $this->portal_base_url();
        $calendar_instance_id = function_exists('wp_unique_id')
            ? wp_unique_id('hc-fac-cal-')
            : 'hc-fac-cal-' . uniqid('', false);

        return Renderer::render('facility-calendar', array(
            'blocks' => $blocks,
            'atts' => $atts,
            'portal_base_url' => $portal,
            'facility_slug' => $atts['facility'],
            'calendar_instance_id' => $calendar_instance_id,
        ));
    }
}
