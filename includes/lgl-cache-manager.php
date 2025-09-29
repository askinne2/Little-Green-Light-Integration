<?php
/**
 * LGL Cache Manager
 * 
 * Provides caching functionality for expensive LGL API operations.
 * Implements WordPress transient API with fallback mechanisms.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * LGL Cache Manager Class
 * 
 * Handles caching of LGL API responses and expensive operations
 */
class LGL_Cache_Manager {
    
    /**
     * Default cache TTL (1 hour)
     */
    const DEFAULT_TTL = 3600;
    
    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'lgl_cache_';
    
    /**
     * Cache group for object cache
     */
    const CACHE_GROUP = 'lgl_api';
    
    /**
     * Get cached data
     * 
     * @param string $key Cache key
     * @param callable $callback Function to generate data if cache miss
     * @param int $ttl Time to live in seconds
     * @return mixed Cached or fresh data
     */
    public static function remember($key, $callback, $ttl = self::DEFAULT_TTL) {
        $cache_key = self::CACHE_PREFIX . $key;
        
        // Try to get from transient cache first
        $cached_data = get_transient($cache_key);
        
        if (false !== $cached_data) {
            error_log('LGL Cache: HIT for key ' . $key);
            return $cached_data;
        }
        
        error_log('LGL Cache: MISS for key ' . $key);
        
        // Generate fresh data
        try {
            $fresh_data = call_user_func($callback);
            
            // Cache the result
            set_transient($cache_key, $fresh_data, $ttl);
            
            error_log('LGL Cache: STORED for key ' . $key . ' (TTL: ' . $ttl . 's)');
            
            return $fresh_data;
            
        } catch (Exception $e) {
            error_log('LGL Cache: ERROR generating data for key ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Store data in cache
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success/failure
     */
    public static function set($key, $data, $ttl = self::DEFAULT_TTL) {
        $cache_key = self::CACHE_PREFIX . $key;
        
        $result = set_transient($cache_key, $data, $ttl);
        
        if ($result) {
            error_log('LGL Cache: SET key ' . $key . ' (TTL: ' . $ttl . 's)');
        } else {
            error_log('LGL Cache: FAILED to set key ' . $key);
        }
        
        return $result;
    }
    
    /**
     * Get cached data without callback
     * 
     * @param string $key Cache key
     * @return mixed|false Cached data or false if not found
     */
    public static function get($key) {
        $cache_key = self::CACHE_PREFIX . $key;
        $data = get_transient($cache_key);
        
        if (false !== $data) {
            error_log('LGL Cache: GET HIT for key ' . $key);
        } else {
            error_log('LGL Cache: GET MISS for key ' . $key);
        }
        
        return $data;
    }
    
    /**
     * Delete cached data
     * 
     * @param string $key Cache key
     * @return bool Success/failure
     */
    public static function delete($key) {
        $cache_key = self::CACHE_PREFIX . $key;
        $result = delete_transient($cache_key);
        
        if ($result) {
            error_log('LGL Cache: DELETED key ' . $key);
        }
        
        return $result;
    }
    
    /**
     * Clear all LGL cache entries
     * 
     * @return int Number of cache entries cleared
     */
    public static function clear_all() {
        global $wpdb;
        
        $cleared = 0;
        
        // Clear transients
        $transient_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%'
            )
        );
        
        foreach ($transient_keys as $transient_key) {
            $key = str_replace('_transient_', '', $transient_key);
            if (delete_transient(str_replace('_transient_' . self::CACHE_PREFIX, '', $transient_key))) {
                $cleared++;
            }
        }
        
        error_log('LGL Cache: CLEARED ' . $cleared . ' cache entries');
        
        return $cleared;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public static function get_stats() {
        global $wpdb;
        
        $transient_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%'
            )
        );
        
        return [
            'total_entries' => (int) $transient_count,
            'cache_prefix' => self::CACHE_PREFIX,
            'default_ttl' => self::DEFAULT_TTL,
            'cache_group' => self::CACHE_GROUP
        ];
    }
    
    /**
     * Cache LGL API response
     * 
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @param mixed $response API response
     * @param int $ttl Cache TTL
     */
    public static function cache_api_response($endpoint, $params, $response, $ttl = self::DEFAULT_TTL) {
        $cache_key = 'api_' . md5($endpoint . serialize($params));
        return self::set($cache_key, $response, $ttl);
    }
    
    /**
     * Get cached API response
     * 
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @return mixed|false Cached response or false
     */
    public static function get_cached_api_response($endpoint, $params) {
        $cache_key = 'api_' . md5($endpoint . serialize($params));
        return self::get($cache_key);
    }
    
    /**
     * Cache constituent data
     * 
     * @param string $constituent_id LGL constituent ID
     * @param array $data Constituent data
     * @param int $ttl Cache TTL
     */
    public static function cache_constituent($constituent_id, $data, $ttl = self::DEFAULT_TTL) {
        $cache_key = 'constituent_' . $constituent_id;
        return self::set($cache_key, $data, $ttl);
    }
    
    /**
     * Get cached constituent data
     * 
     * @param string $constituent_id LGL constituent ID
     * @return mixed|false Cached data or false
     */
    public static function get_cached_constituent($constituent_id) {
        $cache_key = 'constituent_' . $constituent_id;
        return self::get($cache_key);
    }
    
    /**
     * Invalidate constituent cache
     * 
     * @param string $constituent_id LGL constituent ID
     */
    public static function invalidate_constituent($constituent_id) {
        $cache_key = 'constituent_' . $constituent_id;
        return self::delete($cache_key);
    }
    
    /**
     * Cache payment data
     * 
     * @param string $payment_id LGL payment ID
     * @param array $data Payment data
     * @param int $ttl Cache TTL
     */
    public static function cache_payment($payment_id, $data, $ttl = self::DEFAULT_TTL) {
        $cache_key = 'payment_' . $payment_id;
        return self::set($cache_key, $data, $ttl);
    }
    
    /**
     * Get cached payment data
     * 
     * @param string $payment_id LGL payment ID
     * @return mixed|false Cached data or false
     */
    public static function get_cached_payment($payment_id) {
        $cache_key = 'payment_' . $payment_id;
        return self::get($cache_key);
    }
    
    /**
     * Warm up cache with frequently accessed data
     */
    public static function warm_cache() {
        error_log('LGL Cache: Starting cache warm-up');
        
        // This could be expanded to pre-load frequently accessed data
        // For now, just log that warm-up was initiated
        
        error_log('LGL Cache: Cache warm-up completed');
    }
    
    /**
     * Schedule cache cleanup
     */
    public static function schedule_cleanup() {
        if (!wp_next_scheduled('lgl_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'lgl_cache_cleanup');
        }
    }
    
    /**
     * Initialize cache invalidation hooks
     */
    public static function init_cache_invalidation() {
        // Invalidate order cache when orders are updated
        add_action('woocommerce_new_order', [self::class, 'invalidate_order_cache']);
        add_action('woocommerce_update_order', [self::class, 'invalidate_order_cache']);
        add_action('woocommerce_order_status_changed', [self::class, 'invalidate_order_cache']);
        
        // Invalidate user cache when user data is updated
        add_action('profile_update', [self::class, 'invalidate_user_cache']);
        add_action('updated_user_meta', [self::class, 'invalidate_user_cache_on_meta_update'], 10, 4);
        
        // Invalidate event cache when events are updated
        add_action('save_post', [self::class, 'invalidate_event_cache_on_save']);
        add_action('delete_post', [self::class, 'invalidate_event_cache_on_delete']);
        
        // Invalidate API cache when LGL data might have changed
        add_action('lgl_api_data_updated', [self::class, 'invalidate_api_cache']);
        
        error_log('LGL Cache: Invalidation hooks initialized');
    }
    
    /**
     * Invalidate order-related cache
     */
    public static function invalidate_order_cache($order_id = null) {
        // Clear all order-related cache entries
        global $wpdb;
        
        $cache_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . 'filtered_orders_%'
            )
        );
        
        $cleared = 0;
        foreach ($cache_keys as $cache_key) {
            $key = str_replace('_transient_' . self::CACHE_PREFIX, '', $cache_key);
            if (delete_transient($key)) {
                $cleared++;
            }
        }
        
        // Also clear dashboard widget cache
        $dashboard_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . 'lgl_orders_%'
            )
        );
        
        foreach ($dashboard_keys as $cache_key) {
            $key = str_replace('_transient_' . self::CACHE_PREFIX, '', $cache_key);
            delete_transient($key);
            $cleared++;
        }
        
        if ($cleared > 0) {
            error_log('LGL Cache: Invalidated ' . $cleared . ' order cache entries due to order update');
        }
    }
    
    /**
     * Invalidate user-related cache
     */
    public static function invalidate_user_cache($user_id) {
        // Clear user-specific cache entries
        $cache_key = 'user_' . $user_id;
        self::delete($cache_key);
        
        // Clear order cache since user data affects order formatting
        self::invalidate_order_cache();
        
        error_log('LGL Cache: Invalidated user cache for user ID ' . $user_id);
    }
    
    /**
     * Invalidate user cache when specific meta is updated
     */
    public static function invalidate_user_cache_on_meta_update($meta_id, $user_id, $meta_key, $meta_value) {
        // Only invalidate for LGL-related meta keys
        $lgl_meta_keys = [
            'lgl_constituent_id',
            'lgl_id', 
            'user-membership-type',
            'user-subscription-status',
            'user-membership-start-date',
            'user-membership-renewal-date',
            'user-subscription-id'
        ];
        
        if (in_array($meta_key, $lgl_meta_keys)) {
            self::invalidate_user_cache($user_id);
        }
    }
    
    /**
     * Invalidate event cache when events are saved
     */
    public static function invalidate_event_cache_on_save($post_id) {
        if (get_post_type($post_id) === 'ui-events') {
            // Clear events newsletter cache
            self::delete('lgl_events_newsletter_content');
            
            error_log('LGL Cache: Invalidated event cache for post ID ' . $post_id);
        }
    }
    
    /**
     * Invalidate event cache when events are deleted
     */
    public static function invalidate_event_cache_on_delete($post_id) {
        if (get_post_type($post_id) === 'ui-events') {
            self::delete('lgl_events_newsletter_content');
            
            error_log('LGL Cache: Invalidated event cache due to event deletion');
        }
    }
    
    /**
     * Invalidate API cache
     */
    public static function invalidate_api_cache($endpoint = null) {
        if ($endpoint) {
            // Clear specific endpoint cache
            $cache_keys = ['api_' . md5($endpoint)];
        } else {
            // Clear all API cache
            global $wpdb;
            $cache_keys = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                    '_transient_' . self::CACHE_PREFIX . 'api_%'
                )
            );
        }
        
        $cleared = 0;
        foreach ($cache_keys as $cache_key) {
            $key = str_replace('_transient_' . self::CACHE_PREFIX, '', $cache_key);
            if (self::delete($key)) {
                $cleared++;
            }
        }
        
        if ($cleared > 0) {
            error_log('LGL Cache: Invalidated ' . $cleared . ' API cache entries');
        }
    }
    
    /**
     * Cleanup expired cache entries
     */
    public static function cleanup_expired() {
        // WordPress handles transient cleanup automatically,
        // but we can add custom logic here if needed
        
        error_log('LGL Cache: Cleanup process initiated');
        
        // Get cache stats before cleanup
        $stats_before = self::get_stats();
        
        // Force cleanup of expired transients
        delete_expired_transients(true);
        
        // Get cache stats after cleanup
        $stats_after = self::get_stats();
        
        $cleaned = $stats_before['total_entries'] - $stats_after['total_entries'];
        error_log('LGL Cache: Cleanup completed - removed ' . $cleaned . ' entries');
        
        return $cleaned;
    }
}

// Register cleanup hook
add_action('lgl_cache_cleanup', [LGL_Cache_Manager::class, 'cleanup_expired']);

// Schedule cleanup on plugin activation
register_activation_hook(__FILE__, [LGL_Cache_Manager::class, 'schedule_cleanup']);
