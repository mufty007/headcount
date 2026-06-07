<?php
namespace Headcount\Core;

/**
 * Plugin Activation Handler
 * Handles tasks that need to run when the plugin is activated
 */
class Activator {
    
    /**
     * Run activation tasks
     */
    public static function activate() {
        global $wpdb;
        
        // Set default options if they don't exist
        self::set_default_options();
        
        // Create cache statistics table
        self::create_cache_stats_table();
        
        // Schedule cache warming cron job
        if (!wp_next_scheduled('headcount_warm_cache')) {
            wp_schedule_event(time(), 'hourly', 'headcount_warm_cache');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log activation
        if (get_option('headcount_debug_mode', 'off') === 'on') {
            error_log('Headcount plugin activated - Version ' . HEADCOUNT_VERSION);
        }
    }
    
    /**
     * Set default options
     */
    private static function set_default_options() {
        $defaults = array(
            'headcount_api_url' => '',
            'headcount_api_key' => '',
            'headcount_event_details_url' => '', // URL to event details page (e.g., https://events.imcaindy.org/portal/event-details.php)
            'headcount_public_base_url' => '', // Origin where Headcount is hosted (e.g. https://events.example.org) for banner images
            'headcount_cache_duration' => 5, // minutes
            'headcount_debug_mode' => 'off',
            'headcount_version' => HEADCOUNT_VERSION,
            'headcount_theme' => 'light',
            'headcount_events_per_page' => 10,
            'headcount_show_past_events' => 'no',
            'headcount_date_format' => 'F j, Y',
            'headcount_time_format' => 'g:i A'
        );
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
    
    /**
     * Create cache statistics table
     */
    private static function create_cache_stats_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'headcount_cache_stats';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            hit_count bigint(20) DEFAULT 0,
            miss_count bigint(20) DEFAULT 0,
            last_accessed datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY cache_key (cache_key),
            KEY last_accessed (last_accessed)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Check if this is a new installation or upgrade
     */
    private static function is_upgrade() {
        $current_version = get_option('headcount_version');
        return $current_version && version_compare($current_version, HEADCOUNT_VERSION, '<');
    }
    
    /**
     * Handle version upgrades
     */
    private static function handle_upgrade() {
        $current_version = get_option('headcount_version');
        
        // Add upgrade logic here for future versions
        // Example:
        // if (version_compare($current_version, '2.1.0', '<')) {
        //     self::upgrade_to_2_1_0();
        // }
        
        // Update version
        update_option('headcount_version', HEADCOUNT_VERSION);
    }
}
