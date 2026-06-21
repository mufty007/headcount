<?php
namespace Headcount\Core;

/**
 * Enhanced API Client for Headcount
 * Provides comprehensive API access with caching, retry logic, and error handling
 */
class APIClient {
    
    private $api_url;
    private $api_key;
    private $debug_mode;
    private $cache;
    
    public function __construct() {
        $this->refresh_config();
        $this->cache = Cache::getInstance();
    }

    public function refresh_config() {
        $this->api_url = get_option('headcount_api_url');
        $this->api_key = get_option('headcount_api_key');
        $this->debug_mode = get_option('headcount_debug_mode', 'off') === 'on';
        
        if (!empty($this->api_url)) {
            $this->api_url = rtrim($this->api_url, '/');
            // If …/public was given without /api, append the API folder (matches Headcount public/api layout).
            if (preg_match('#/public/?$#', $this->api_url) && !preg_match('#/api/?$#', $this->api_url)) {
                $this->api_url = rtrim($this->api_url, '/') . '/api';
            }
        }
    }
    
    /**
     * Get events from API
     */
    public function get_events($args = array()) {
        $defaults = array(
            'limit' => 10,
            'category' => '',
            'start_date' => date('Y-m-d'),
            'show_past' => false
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Remove start_date if showing past events
        if ($args['show_past']) {
            unset($args['start_date']);
        }
        
        $url = rtrim($this->api_url, '/') . '/public-events.php?' . http_build_query($args);
        
        return $this->make_request($url);
    }
    
    /**
     * Get single event
     */
    public function get_event($event_id) {
        $url = rtrim($this->api_url, '/') . '/public-events.php?id=' . intval($event_id);
        
        $response = $this->make_request($url);
        
        if (isset($response['success']) && $response['success'] && isset($response['events'])) {
            if (!empty($response['events'])) {
                $response['event'] = $response['events'][0];
            } else {
                $response['success'] = false;
                $response['message'] = 'Event not found';
            }
        }
        
        return $response;
    }
    
    /**
     * Get event categories
     */
    public function get_categories() {
        // For now, extract categories from events
        // In future, this could be a dedicated endpoint
        $cache_key = 'categories_list';
        $cached = $this->cache->get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $events_response = $this->get_events(array('limit' => 100));
        
        if (!isset($events_response['success']) || !$events_response['success']) {
            return array('success' => false, 'categories' => array());
        }
        
        $categories = array();
        foreach ($events_response['events'] as $event) {
            if (!empty($event['category']) && !in_array($event['category'], $categories)) {
                $categories[] = $event['category'];
            }
        }
        
        sort($categories);
        
        $result = array(
            'success' => true,
            'categories' => $categories
        );
        
        $this->cache->set($cache_key, $result, 3600); // Cache for 1 hour
        
        return $result;
    }
    
    /**
     * Public programs feed (API key)
     */
    public function get_programs($args = array()) {
        $defaults = array(
            'limit' => 50,
            'id' => null,
        );
        $args = wp_parse_args($args, $defaults);
        // Same directory as public-events.php (e.g. …/Headcount/api/public-programs.php)
        $url = rtrim($this->api_url, '/') . '/public-programs.php?' . http_build_query(array_filter(array(
            'limit' => $args['limit'],
            'id' => $args['id'],
        )));
        return $this->make_request($url);
    }

    /**
     * Combined calendar: events + program sessions
     */
    public function get_calendar_feed($args = array()) {
        $defaults = array(
            'start' => date('Y-m-01'),
            'end' => date('Y-m-t', strtotime('+2 months')),
        );
        $args = wp_parse_args($args, $defaults);
        $url = rtrim($this->api_url, '/') . '/public-calendar-feed.php?' . http_build_query(array(
            'start' => $args['start'],
            'end' => $args['end'],
        ));
        return $this->make_request($url);
    }

    /**
     * Public facilities list (API key)
     */
    public function get_facilities($args = array()) {
        $url = rtrim($this->api_url, '/') . '/public-facilities?' . http_build_query(array_filter($args));
        return $this->make_request($url);
    }

    /**
     * Single active facility by id or slug (API key).
     *
     * @param array{id?:int,slug?:string} $args
     */
    public function get_facility($args = array()) {
        $identifier = null;
        if (!empty($args['id'])) {
            $identifier = (int) $args['id'];
        } elseif (!empty($args['slug'])) {
            $identifier = $args['slug'];
        }
        if ($identifier === null) {
            return array('success' => false, 'message' => 'Facility id or slug required');
        }
        $url = rtrim($this->api_url, '/') . '/public-facilities/' . rawurlencode((string) $identifier);
        return $this->make_request($url);
    }

    /**
     * Facility availability blocks for calendar (API key)
     */
    public function get_facility_availability($facility_slug, $args = array()) {
        $args['facility_slug'] = $facility_slug;
        $url = rtrim($this->api_url, '/') . '/public-facility-availability?' . http_build_query(array_filter($args));
        return $this->make_request($url);
    }

    /**
     * Search events by keyword
     */
    public function search_events($query, $args = array()) {
        $defaults = array(
            'limit' => 50
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Get all events and filter client-side
        // In future, this could be a server-side search endpoint
        $events_response = $this->get_events($args);
        
        if (!isset($events_response['success']) || !$events_response['success']) {
            return $events_response;
        }
        
        $query = strtolower($query);
        $filtered_events = array();
        
        foreach ($events_response['events'] as $event) {
            $searchable = strtolower(
                $event['title'] . ' ' . 
                $event['description'] . ' ' . 
                $event['location'] . ' ' . 
                $event['category']
            );
            
            if (strpos($searchable, $query) !== false) {
                $filtered_events[] = $event;
            }
        }
        
        return array(
            'success' => true,
            'events' => $filtered_events,
            'count' => count($filtered_events)
        );
    }
    
    /**
     * Submit RSVP for an event
     * Note: This requires the main application to have an RSVP endpoint
     */
    public function submit_rsvp($event_id, $data) {
        $url = rtrim($this->api_url, '/') . '/rsvp.php';
        
        $body = array_merge($data, array('event_id' => $event_id));
        
        return $this->make_post_request($url, $body);
    }
    
    /**
     * Get organization details
     */
    public function get_organization() {
        $cache_key = 'organization_details';
        $cached = $this->cache->get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Get organization info from a test API call
        $response = $this->get_events(array('limit' => 1));
        
        if (isset($response['success']) && $response['success'] && isset($response['organization'])) {
            $result = array(
                'success' => true,
                'organization' => $response['organization']
            );
            
            $this->cache->set($cache_key, $result, 86400); // Cache for 24 hours
            
            return $result;
        }
        
        return array('success' => false, 'message' => 'Could not retrieve organization details');
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        $start_time = microtime(true);
        
        $response = $this->get_events(array('limit' => 1));
        
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000, 2); // ms
        
        if (isset($response['success']) && $response['success']) {
            return array(
                'success' => true,
                'message' => 'Connected successfully!',
                'response_time' => $response_time,
                'organization' => $response['organization'] ?? 'Unknown',
                'event_count' => $response['count'] ?? 0
            );
        }
        
        return array(
            'success' => false,
            'message' => $response['message'] ?? 'Connection failed',
            'response_time' => $response_time
        );
    }
    
    /**
     * Make GET API request with caching and exponential backoff retry logic
     */
    private function make_request($url, $retries = 3) {
        if (empty($this->api_url) || empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'API URL and API key are not configured.'
            );
        }
        
        // Check cache first
        $cache_key = md5($url);
        $cached = $this->cache->get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $attempt = 0;
        $response = null;
        
        while ($attempt <= $retries) {
            $response = wp_remote_get($url, array(
                'timeout' => 15,
                'headers' => array(
                    'X-API-Key' => $this->api_key,
                    'Accept' => 'application/json',
                    'User-Agent' => 'Headcount-WordPress-Plugin/' . HEADCOUNT_VERSION
                )
            ));
            
            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                
                // Success: 200 OK
                if ($response_code === 200) {
                    break;
                }
                
                // Don't retry on client errors (4xx) unless it's 429 Too Many Requests
                if ($response_code >= 400 && $response_code < 500 && $response_code !== 429) {
                    break;
                }
            }
            
            $attempt++;
            if ($attempt <= $retries) {
                // Exponential backoff: 0.5s, 1s, 2s
                $wait_time = pow(2, $attempt - 1) * 500000; // microseconds
                
                if ($this->debug_mode) {
                    error_log('Headcount API: Retry attempt ' . $attempt . ' for ' . $url . ' (waiting ' . ($wait_time / 1000) . 'ms)');
                }
                
                usleep($wait_time);
            }
        }
        
        if (is_wp_error($response)) {
            $this->log_error('Connection error: ' . $response->get_error_message(), $url);
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($response_code !== 200) {
            $error_message = isset($data['message']) ? $data['message'] : 'HTTP Error ' . $response_code;
            $this->log_error($error_message, $url, $body);
            return array(
                'success' => false,
                'message' => $error_message,
                'http_code' => $response_code
            );
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error('Invalid JSON response', $url, $body);
            return array(
                'success' => false,
                'message' => 'Invalid JSON response from API'
            );
        }

        // Unwrap Laravel ApiResponse envelope: { success, data: { ... } } -> merge data to top level
        if (isset($data['success']) && $data['success'] && isset($data['data']) && is_array($data['data'])) {
            $data = array_merge($data, $data['data']);
            unset($data['data']);
        }

        // Cache successful responses
        if (isset($data['success']) && $data['success']) {
            $this->cache->set($cache_key, $data);
        }
        
        return $data;
    }
    
    /**
     * Make POST API request (for RSVP, etc.)
     */
    private function make_post_request($url, $body) {
        if (empty($this->api_url) || empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'API URL and API key are not configured.'
            );
        }
        
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array(
                'X-API-Key' => $this->api_key,
                'Content-Type' => 'application/json',
                'User-Agent' => 'Headcount-WordPress-Plugin/' . HEADCOUNT_VERSION
            ),
            'body' => json_encode($body)
        ));
        
        if (is_wp_error($response)) {
            $this->log_error('Connection error: ' . $response->get_error_message(), $url);
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);
        
        if ($response_code !== 200 && $response_code !== 201) {
            $error_message = isset($data['message']) ? $data['message'] : 'HTTP Error ' . $response_code;
            $this->log_error($error_message, $url, $response_body);
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
        
        return $data ?? array('success' => true);
    }
    
    /**
     * Log error messages
     */
    private function log_error($message, $url, $body = '') {
        if (!$this->debug_mode) return;
        
        $log_msg = "Headcount API Error: " . $message . "\nURL: " . $url;
        if (!empty($body)) {
            $log_msg .= "\nResponse: " . substr($body, 0, 500);
        }
        error_log($log_msg);
    }

    /**
     * Clear all cache
     */
    public function clear_cache() {
        return $this->cache->clear_all();
    }
    
    /**
     * Warm cache with frequently accessed data
     */
    public function warm_cache() {
        // Pre-load upcoming events
        $this->get_events(array('limit' => 10));
        
        // Pre-load categories
        $this->get_categories();
        
        // Pre-load organization details
        $this->get_organization();
        
        return true;
    }
}
