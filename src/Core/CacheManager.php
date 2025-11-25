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

namespace UpstateInternational\LGL\Core;

/**
 * Cache Manager Class
 * 
 * Handles caching of LGL API responses and expensive operations
 */
class CacheManager {
    
    /**
     * Class instance
     * 
     * @var CacheManager|null
     */
    private static $instance = null;
    
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
     * Get instance
     * 
     * @return CacheManager
     */
    public static function getInstance(): CacheManager {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Initialize cache manager
     */
    public static function init() {
        // Schedule cleanup on plugin activation
        static::scheduleCleanup();
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache Manager: Initialized successfully');
    }
    
    /**
     * Get cached data
     * 
     * @param string $key Cache key
     * @param callable $callback Function to generate data if cache miss
     * @param int $ttl Time to live in seconds
     * @return mixed Cached or fresh data
     */
    public static function remember($key, $callback, $ttl = self::DEFAULT_TTL) {
        $cache_key = self::CACHE_PREFIX . self::getEnvironmentPrefix() . $key;
        
        // Try to get from transient cache first
        $cached_data = get_transient($cache_key);
        
        if (false !== $cached_data) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: HIT for key ' . $key);
            return $cached_data;
        }
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: MISS for key ' . $key);
        
        // Generate fresh data
        try {
            $fresh_data = call_user_func($callback);
            
            // Cache the result
            set_transient($cache_key, $fresh_data, $ttl);
            
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: STORED for key ' . $key . ' (TTL: ' . $ttl . 's)');
            
            return $fresh_data;
            
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: ERROR generating data for key ' . $key . ': ' . $e->getMessage());
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
        $cache_key = self::CACHE_PREFIX . self::getEnvironmentPrefix() . $key;
        
        $result = set_transient($cache_key, $data, $ttl);
        
        if ($result) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: SET key ' . $key . ' (TTL: ' . $ttl . 's)');
        } else {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: FAILED to set key ' . $key);
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
        $cache_key = self::CACHE_PREFIX . self::getEnvironmentPrefix() . $key;
        $data = get_transient($cache_key);
        
        if (false !== $data) {
            // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: GET HIT for key ' . $key);
        } else {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: GET MISS for key ' . $key);
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
        $cache_key = self::CACHE_PREFIX . self::getEnvironmentPrefix() . $key;
        $result = delete_transient($cache_key);
        
        if ($result) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: DELETED key ' . $key);
        }
        
        return $result;
    }
    
    /**
     * Get environment prefix for cache keys
     * 
     * @return string Environment prefix ('dev_' or 'live_')
     */
    private static function getEnvironmentPrefix(): string {
        // Use static cache to avoid repeated option lookups and prevent recursion
        static $cached_prefix = null;
        static $is_loading = false;
        
        // Return cached value if available
        if ($cached_prefix !== null) {
            return $cached_prefix;
        }
        
        // Prevent concurrent calls from causing multiple DB queries
        if ($is_loading) {
            // If we're already loading, return default to prevent recursion
            return 'live_';
        }
        
        $is_loading = true;
        
        try {
            // CRITICAL: Read directly from database WITHOUT going through SettingsManager or any caching
            // This prevents circular dependency: CacheManager -> SettingsManager -> CacheManager
            // Direct DB query bypasses WordPress option cache and transient system
            global $wpdb;
            $option_value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'lgl_integration_settings'
            ));
            
            $env = 'live'; // Default fallback
            
            if ($option_value) {
                $settings = maybe_unserialize($option_value);
                if (is_array($settings) && isset($settings['environment'])) {
                    $env = $settings['environment'];
                }
            }
            
            // Validate environment value (must be 'dev' or 'live')
            if (!in_array($env, ['dev', 'live'])) {
                $env = 'live';
            }
            
            // Cache prefix in static variable for this request
            $cached_prefix = $env . '_';
            return $cached_prefix;
            
        } finally {
            $is_loading = false;
        }
    }
    
    /**
     * Clear all LGL cache entries
     * 
     * MEMORY FIX: Process in batches to prevent memory exhaustion with large cache sets
     * 
     * @return int Number of cache entries cleared
     */
    public static function clearAll() {
        global $wpdb;
        
        $cleared = 0;
        $batch_size = 100; // Process 100 cache entries at a time
        $offset = 0;
        $has_more = true;
        
        while ($has_more) {
            // Get cache keys in batches
            $transient_keys = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d OFFSET %d",
                    '_transient_' . self::CACHE_PREFIX . '%',
                    $batch_size,
                    $offset
                )
            );
            
            if (empty($transient_keys)) {
                $has_more = false;
                break;
            }
            
            foreach ($transient_keys as $transient_key) {
                $key = str_replace('_transient_' . self::CACHE_PREFIX, '', $transient_key);
                if (delete_transient($key)) {
                    $cleared++;
                }
            }
            
            // Check if we got fewer than requested (last batch)
            if (count($transient_keys) < $batch_size) {
                $has_more = false;
            } else {
                $offset += $batch_size;
            }
            
            // Free memory
            unset($transient_keys);
            
            // Safety limit
            if ($offset > 10000) {
                \UpstateInternational\LGL\LGL\Helper::getInstance()->warning('LGL Cache: clearAll hit safety limit', ['offset' => $offset]);
                break;
            }
        }
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: CLEARED ' . $cleared . ' cache entries');
        
        return $cleared;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public static function getStats() {
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
    public static function cacheApiResponse($endpoint, $params, $response, $ttl = self::DEFAULT_TTL) {
        $cache_key = 'api_' . md5($endpoint . serialize($params));
        return static::set($cache_key, $response, $ttl);
    }
    
    /**
     * Get cached API response
     * 
     * @param string $endpoint API endpoint
     * @param array $params Request parameters
     * @return mixed|false Cached response or false
     */
    public static function getCachedApiResponse($endpoint, $params) {
        $cache_key = 'api_' . md5($endpoint . serialize($params));
        return static::get($cache_key);
    }
    
    /**
     * Cache constituent data
     * 
     * @param string $constituent_id LGL constituent ID
     * @param array $data Constituent data
     * @param int $ttl Cache TTL
     */
    public static function cacheConstituent($constituent_id, $data, $ttl = self::DEFAULT_TTL) {
        $cache_key = 'constituent_' . $constituent_id;
        return static::set($cache_key, $data, $ttl);
    }
    
    /**
     * Get cached constituent data
     * 
     * @param string $constituent_id LGL constituent ID
     * @return mixed|false Cached data or false
     */
    public static function getCachedConstituent($constituent_id) {
        $cache_key = 'constituent_' . $constituent_id;
        return static::get($cache_key);
    }
    
    /**
     * Invalidate constituent cache
     * 
     * @param string $constituent_id LGL constituent ID
     */
    public static function invalidateConstituent($constituent_id) {
        $cache_key = 'constituent_' . $constituent_id;
        return static::delete($cache_key);
    }
    
    /**
     * Cache payment data
     * 
     * @param string $payment_id LGL payment ID
     * @param array $data Payment data
     * @param int $ttl Cache TTL
     */
    public static function cachePayment($payment_id, $data, $ttl = self::DEFAULT_TTL) {
        $cache_key = 'payment_' . $payment_id;
        return static::set($cache_key, $data, $ttl);
    }
    
    /**
     * Get cached payment data
     * 
     * @param string $payment_id LGL payment ID
     * @return mixed|false Cached data or false
     */
    public static function getCachedPayment($payment_id) {
        $cache_key = 'payment_' . $payment_id;
        return static::get($cache_key);
    }
    
    /**
     * Warm up cache with frequently accessed data
     */
    public static function warmCache() {
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Starting cache warm-up');
        
        // This could be expanded to pre-load frequently accessed data
        // For now, just log that warm-up was initiated
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Cache warm-up completed');
    }
    
    /**
     * Schedule cache cleanup
     */
    public static function scheduleCleanup() {
        if (!wp_next_scheduled('lgl_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'lgl_cache_cleanup');
        }
    }
    
    /**
     * Initialize cache invalidation hooks
     */
    public static function initCacheInvalidation() {
        // Invalidate order cache when orders are updated
        add_action('woocommerce_new_order', [static::class, 'invalidateOrderCache']);
        add_action('woocommerce_update_order', [static::class, 'invalidateOrderCache']);
        add_action('woocommerce_order_status_changed', [static::class, 'invalidateOrderCache']);
        
        // Invalidate user cache when user data is updated
        add_action('profile_update', [static::class, 'invalidateUserCache']);
        add_action('updated_user_meta', [static::class, 'invalidateUserCacheOnMetaUpdate'], 10, 4);
        
        // Invalidate event cache when events are updated
        add_action('save_post', [static::class, 'invalidateEventCacheOnSave']);
        add_action('delete_post', [static::class, 'invalidateEventCacheOnDelete']);
        
        // Invalidate API cache when LGL data might have changed
        add_action('lgl_api_data_updated', [static::class, 'invalidateApiCache']);
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Invalidation hooks initialized');
    }
    
    /**
     * Invalidate order-related cache
     * 
     * MEMORY FIX: Process in batches to prevent memory exhaustion
     */
    public static function invalidateOrderCache($order_id = null) {
        global $wpdb;
        
        $cleared = 0;
        $batch_size = 100;
        $offset = 0;
        $has_more = true;
        
        // Clear filtered orders cache
        while ($has_more) {
            $cache_keys = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d OFFSET %d",
                    '_transient_' . self::CACHE_PREFIX . 'filtered_orders_%',
                    $batch_size,
                    $offset
                )
            );
            
            if (empty($cache_keys)) {
                $has_more = false;
                break;
            }
            
            foreach ($cache_keys as $cache_key) {
                $key = str_replace('_transient_' . self::CACHE_PREFIX, '', $cache_key);
                if (delete_transient($key)) {
                    $cleared++;
                }
            }
            
            if (count($cache_keys) < $batch_size) {
                $has_more = false;
            } else {
                $offset += $batch_size;
            }
            
            unset($cache_keys);
            
            if ($offset > 10000) {
                break;
            }
        }
        
        // Also clear dashboard widget cache
        $offset = 0;
        $has_more = true;
        while ($has_more) {
            $dashboard_keys = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d OFFSET %d",
                    '_transient_' . self::CACHE_PREFIX . 'lgl_orders_%',
                    $batch_size,
                    $offset
                )
            );
            
            if (empty($dashboard_keys)) {
                $has_more = false;
                break;
            }
            
            foreach ($dashboard_keys as $cache_key) {
                $key = str_replace('_transient_' . self::CACHE_PREFIX, '', $cache_key);
                delete_transient($key);
                $cleared++;
            }
            
            if (count($dashboard_keys) < $batch_size) {
                $has_more = false;
            } else {
                $offset += $batch_size;
            }
            
            unset($dashboard_keys);
            
            if ($offset > 10000) {
                break;
            }
        }
        
        if ($cleared > 0) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Invalidated ' . $cleared . ' order cache entries due to order update');
        }
    }
    
    /**
     * Invalidate user-related cache
     */
    public static function invalidateUserCache($user_id) {
        // Clear user-specific cache entries
        $cache_key = 'user_' . $user_id;
        static::delete($cache_key);
        
        // Clear order cache since user data affects order formatting
        static::invalidateOrderCache();
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Invalidated user cache for user ID ' . $user_id);
    }
    
    /**
     * Invalidate user cache when specific meta is updated
     */
    public static function invalidateUserCacheOnMetaUpdate($meta_id, $user_id, $meta_key, $meta_value) {
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
            static::invalidateUserCache($user_id);
        }
    }
    
    /**
     * Invalidate event cache when events are saved
     */
    public static function invalidateEventCacheOnSave($post_id) {
        if (get_post_type($post_id) === 'ui-events') {
            // Clear events newsletter cache
            static::delete('lgl_events_newsletter_content');
            
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Invalidated event cache for post ID ' . $post_id);
        }
    }
    
    /**
     * Invalidate event cache when events are deleted
     */
    public static function invalidateEventCacheOnDelete($post_id) {
        if (get_post_type($post_id) === 'ui-events') {
            static::delete('lgl_events_newsletter_content');
            
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Invalidated event cache due to event deletion');
        }
    }
    
    /**
     * Invalidate API cache
     * 
     * MEMORY FIX: Process in batches when clearing all API cache
     */
    public static function invalidateApiCache($endpoint = null) {
        if ($endpoint) {
            // Clear specific endpoint cache
            $cache_keys = ['api_' . md5($endpoint)];
        } else {
            // Clear all API cache in batches
            global $wpdb;
            $cache_keys = [];
            $batch_size = 100;
            $offset = 0;
            $has_more = true;
            
            while ($has_more) {
                $batch_keys = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d OFFSET %d",
                        '_transient_' . self::CACHE_PREFIX . 'api_%',
                        $batch_size,
                        $offset
                    )
                );
                
                if (empty($batch_keys)) {
                    $has_more = false;
                    break;
                }
                
                $cache_keys = array_merge($cache_keys, $batch_keys);
                
                if (count($batch_keys) < $batch_size) {
                    $has_more = false;
                } else {
                    $offset += $batch_size;
                }
                
                unset($batch_keys);
                
                if ($offset > 10000) {
                    break;
                }
            }
        }
        
        $cleared = 0;
        foreach ($cache_keys as $cache_key) {
            $key = str_replace('_transient_' . self::CACHE_PREFIX, '', $cache_key);
            if (static::delete($key)) {
                $cleared++;
            }
        }
        
        if ($cleared > 0) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Invalidated ' . $cleared . ' API cache entries');
        }
    }
    
    /**
     * Cleanup expired cache entries
     */
    public static function cleanupExpired() {
        // WordPress handles transient cleanup automatically,
        // but we can add custom logic here if needed
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Cleanup process initiated');
        
        // Get cache stats before cleanup
        $stats_before = static::getStats();
        
        // Force cleanup of expired transients
        delete_expired_transients(true);
        
        // Get cache stats after cleanup
        $stats_after = static::getStats();
        
        $cleaned = $stats_before['total_entries'] - $stats_after['total_entries'];
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Cache: Cleanup completed - removed ' . $cleaned . ' entries');
        
        return $cleaned;
    }
    
    /**
     * Flush all cache entries
     * 
     * @return bool Success status
     */
    public function flush(): bool {
        return static::flushAll();
    }
    
    /**
     * Check if cache is enabled
     * 
     * @return bool True if cache is enabled
     */
    public function isEnabled(): bool {
        return !defined('LGL_DISABLE_CACHE') || !LGL_DISABLE_CACHE;
    }
}

// Register cleanup hook only if WordPress is loaded
if (function_exists('add_action')) {
    add_action('lgl_cache_cleanup', [CacheManager::class, 'cleanupExpired']);
}
