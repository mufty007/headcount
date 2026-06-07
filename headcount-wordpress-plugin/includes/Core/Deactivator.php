<?php
namespace Headcount\Core;

/**
 * Plugin Deactivation Handler
 * Handles cleanup tasks when the plugin is deactivated
 */
class Deactivator {
    
    /**
     * Run deactivation tasks
     */
    public static function deactivate() {
        // Clear scheduled cron jobs
        $timestamp = wp_next_scheduled('headcount_warm_cache');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'headcount_warm_cache');
        }
        
        // Optionally clear cache (based on settings)
        if (get_option('headcount_clear_cache_on_deactivate', 'yes') === 'yes') {
            self::clear_all_cache();
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        if (get_option('headcount_debug_mode', 'off') === 'on') {
            error_log('Headcount plugin deactivated');
        }
    }
    
    /**
     * Clear all plugin cache
     */
    private static function clear_all_cache() {
        global $wpdb;
        
        // Delete all transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_headcount_%' 
             OR option_name LIKE '_transient_timeout_headcount_%'"
        );
        
        // Clear cache stats
        $table_name = $wpdb->prefix . 'headcount_cache_stats';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $wpdb->query("TRUNCATE TABLE $table_name");
        }
    }
}
