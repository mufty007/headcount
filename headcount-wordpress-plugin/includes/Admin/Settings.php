<?php
namespace Headcount\Admin;

use Headcount\Core\Renderer;

/**
 * Admin Settings Class
 */
class Settings {
    
    private $api_client;
    
    public function __construct($api_client) {
        $this->api_client = $api_client;
    }

    public function add_settings_page() {
        add_submenu_page(
            'headcount-dashboard',
            'Headcount Settings',
            'Settings',
            'manage_options',
            'headcount-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('headcount_settings', 'headcount_api_url');
        register_setting('headcount_settings', 'headcount_api_key');
        register_setting('headcount_settings', 'headcount_event_details_url');
        register_setting('headcount_settings', 'headcount_public_base_url', array(
            'sanitize_callback' => function ($value) {
                if ($value === null || $value === '') {
                    return '';
                }
                return esc_url_raw(trim((string) $value));
            },
        ));
        register_setting('headcount_settings', 'headcount_portal_url', array(
            'sanitize_callback' => function ($value) {
                if ($value === null || $value === '') {
                    return '';
                }
                return esc_url_raw(trim((string) $value));
            },
        ));
        register_setting('headcount_settings', 'headcount_cache_duration');
        register_setting('headcount_settings', 'headcount_debug_mode');
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle actions
        $message = '';
        if (isset($_POST['headcount_clear_cache']) && check_admin_referer('headcount_admin_action', 'headcount_admin_nonce')) {
            $this->api_client->clear_cache();
            $message = 'Cache cleared successfully!';
        }

        // Force a config refresh if anything was just saved via options.php
        $this->api_client->refresh_config();

        // Test connection
        $connection_status = $this->test_connection();

        $api_url_opt = (string) get_option('headcount_api_url', '');
        $api_trim = rtrim($api_url_opt, '/');
        $public_programs_example = $api_trim !== '' ? $api_trim . '/public-programs.php' : '';
        $public_calendar_example = $api_trim !== '' ? $api_trim . '/public-calendar-feed.php' : '';

        // Render template
        echo Renderer::render('settings', array(
            'message' => $message,
            'connection' => $connection_status,
            'api_url' => get_option('headcount_api_url'),
            'api_key' => get_option('headcount_api_key'),
            'event_details_url' => get_option('headcount_event_details_url'),
            'public_base_url' => get_option('headcount_public_base_url', ''),
            'portal_url' => get_option('headcount_portal_url', ''),
            'public_programs_example' => $public_programs_example,
            'public_calendar_example' => $public_calendar_example,
            'cache_duration' => get_option('headcount_cache_duration', 5),
            'debug_mode' => get_option('headcount_debug_mode', 'off')
        ), 'admin');
    }

    private function test_connection() {
        $api_url = get_option('headcount_api_url');
        $api_key = get_option('headcount_api_key');
        
        if (empty($api_url) || empty($api_key)) {
            return array('success' => false, 'message' => 'Configuration missing.');
        }
        
        $response = $this->api_client->get_events(array('limit' => 1));
        return array(
            'success' => $response['success'] ?? false,
            'message' => $response['message'] ?? 'Connected successfully!',
            'count' => $response['count'] ?? 0
        );
    }
}
