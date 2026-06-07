<?php
/**
 * Uninstall Script
 * Fired when the plugin is uninstalled
 */

// Exit if accessed directly or not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all plugin options
$options = array(
    'headcount_api_url',
    'headcount_api_key',
    'headcount_event_details_url',
    'headcount_public_base_url',
    'headcount_cache_duration',
    'headcount_debug_mode',
    'headcount_version',
    'headcount_theme',
    'headcount_events_per_page',
    'headcount_show_past_events',
    'headcount_date_format',
    'headcount_time_format',
    'headcount_clear_cache_on_deactivate'
);

foreach ($options as $option) {
    delete_option($option);
}

// Delete all transients
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options} 
     WHERE option_name LIKE '_transient_headcount_%' 
     OR option_name LIKE '_transient_timeout_headcount_%'"
);

// Drop cache statistics table
$table_name = $wpdb->prefix . 'headcount_cache_stats';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Clear any scheduled cron jobs
wp_clear_scheduled_hook('headcount_warm_cache');
