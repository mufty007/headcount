<?php
namespace Headcount\Core;

/**
 * Cache Manager
 * Handles caching operations with statistics tracking
 */
class Cache {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get cached data
     */
    public function get($key) {
        $cache_key = $this->get_cache_key($key);
        $value = get_transient($cache_key);
        
        // Track statistics
        $this->track_access($cache_key, $value !== false);
        
        return $value;
    }
    
    /**
     * Set cached data
     */
    public function set($key, $value, $expiration = null) {
        if ($expiration === null) {
            $expiration = get_option('headcount_cache_duration', 5) * MINUTE_IN_SECONDS;
        }
        
        $cache_key = $this->get_cache_key($key);
        return set_transient($cache_key, $value, $expiration);
    }
    
    /**
     * Delete cached data
     */
    public function delete($key) {
        $cache_key = $this->get_cache_key($key);
        return delete_transient($cache_key);
    }
    
    /**
     * Clear all plugin cache
     */
    public function clear_all() {
        global $wpdb;
        
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_headcount_%' 
             OR option_name LIKE '_transient_timeout_headcount_%'"
        );
        
        return $deleted;
    }
    
    /**
     * Clear cache by pattern
     */
    public function clear_pattern($pattern) {
        global $wpdb;
        
        $like_pattern = '_transient_headcount_' . $wpdb->esc_like($pattern) . '%';
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE %s 
                 OR option_name LIKE %s",
                $like_pattern,
                '_transient_timeout' . substr($like_pattern, 10)
            )
        );
        
        return $deleted;
    }
    
    /**
     * Get cache statistics
     */
    public function get_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'headcount_cache_stats';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return array(
                'total_keys' => 0,
                'total_hits' => 0,
                'total_misses' => 0,
                'hit_rate' => 0
            );
        }
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_keys,
                SUM(hit_count) as total_hits,
                SUM(miss_count) as total_misses
             FROM $table_name",
            ARRAY_A
        );
        
        $total_requests = ($stats['total_hits'] ?? 0) + ($stats['total_misses'] ?? 0);
        $hit_rate = $total_requests > 0 ? round(($stats['total_hits'] / $total_requests) * 100, 2) : 0;
        
        return array(
            'total_keys' => (int)($stats['total_keys'] ?? 0),
            'total_hits' => (int)($stats['total_hits'] ?? 0),
            'total_misses' => (int)($stats['total_misses'] ?? 0),
            'hit_rate' => $hit_rate
        );
    }
    
    /**
     * Get top cached items
     */
    public function get_top_items($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'headcount_cache_stats';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return array();
        }
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cache_key, hit_count, miss_count, last_accessed
                 FROM $table_name
                 ORDER BY (hit_count + miss_count) DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
    
    /**
     * Warm cache with frequently accessed data
     */
    public function warm_cache() {
        // Get API client
        $api_client = new APIClient();
        
        // Pre-load upcoming events
        $api_client->get_events(array('limit' => 10));
        
        // Pre-load categories
        $api_client->get_categories();
        
        // Pre-load organization details
        $api_client->get_organization();
        
        return true;
    }
    
    /**
     * Generate cache key
     */
    private function get_cache_key($key) {
        return 'headcount_' . md5($key);
    }
    
    /**
     * Track cache access for statistics
     */
    private function track_access($cache_key, $is_hit) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'headcount_cache_stats';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return;
        }
        
        $field = $is_hit ? 'hit_count' : 'miss_count';
        
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO $table_name (cache_key, $field, last_accessed)
                 VALUES (%s, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                 $field = $field + 1,
                 last_accessed = NOW()",
                $cache_key
            )
        );
    }
}
