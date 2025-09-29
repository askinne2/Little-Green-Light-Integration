<?php
/**
 * LGL Utilities Class
 * 
 * Shared utility functions for the LGL plugin.
 * Moved from theme to plugin for proper separation of concerns.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Core;

/**
 * Utilities Class
 * 
 * Provides shared utility functions with proper error handling and caching
 */
class Utilities {
    
    /**
     * Initialize utilities
     */
    public static function init() {
        // Register backward compatibility functions
        static::registerBackwardCompatibility();
        
        error_log('LGL Utilities: Initialized successfully');
    }
    
    /**
     * Get filtered orders with caching and error handling
     * 
     * @param \DateTime $start_datetime Start date for filtering
     * @param \DateTime $end_datetime End date for filtering
     * @param array $statuses Order statuses to include
     * @return array Array of WooCommerce order objects
     */
    public static function getFilteredOrders($start_datetime, $end_datetime, $statuses = ['completed']) {
        // Validate WooCommerce availability
        if (!class_exists('WooCommerce') || !function_exists('wc_get_orders')) {
            error_log('LGL Utilities: WooCommerce is not active or wc_get_orders() not available');
            return [];
        }

        // Validate datetime parameters
        if (!($start_datetime instanceof \DateTime) || !($end_datetime instanceof \DateTime)) {
            error_log('LGL Utilities: Invalid datetime parameters provided to getFilteredOrders()');
            return [];
        }

        // Generate cache key
        $cache_key = 'filtered_orders_' . $start_datetime->format('Ymd_His') . '_' . $end_datetime->format('Ymd_His') . '_' . implode('_', $statuses);
        
        // Try to get from cache first
        $cached_orders = CacheManager::get($cache_key);
        if ($cached_orders !== false) {
            return $cached_orders;
        }

        try {
            $args = [
                'status' => $statuses,
                'date_query' => [
                    [
                        'after'     => $start_datetime->format('Y-m-d H:i:s'),
                        'before'    => $end_datetime->format('Y-m-d H:i:s'),
                        'inclusive' => true,
                    ],
                ],
                'limit' => -1, // Get all matching orders
            ];

            $orders = wc_get_orders($args);
            
            if (!is_array($orders)) {
                error_log('LGL Utilities: Invalid response from wc_get_orders()');
                return [];
            }

            // Cache the results for 1 hour
            CacheManager::set($cache_key, $orders, 3600);

            error_log(sprintf(
                'LGL Utilities: Fetched %d orders from %s to %s',
                count($orders),
                $start_datetime->format('Y-m-d H:i:s'),
                $end_datetime->format('Y-m-d H:i:s')
            ));
            
            return $orders;
            
        } catch (\Exception $e) {
            error_log('LGL Utilities: Error fetching orders - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Format order data for display with comprehensive error handling
     * 
     * @param \WC_Order $order WooCommerce order object
     * @return array|null Formatted order data or null on failure
     */
    public static function formatOrderData($order) {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            error_log('LGL Utilities: Invalid order object provided to formatOrderData()');
            return null;
        }

        try {
            $order_id = $order->get_id();
            $customer_id = $order->get_customer_id();
            
            // Validate required order methods exist
            $required_methods = [
                'get_date_created', 'get_billing_first_name', 'get_billing_last_name',
                'get_total', 'get_item_count', 'get_items'
            ];
            
            foreach ($required_methods as $method) {
                if (!method_exists($order, $method)) {
                    error_log('LGL Utilities: Order object missing required method: ' . $method);
                    return null;
                }
            }

            // Get basic order information
            $date_created = $order->get_date_created();
            $date_str = $date_created ? $date_created->date('M j, Y g:i A') : 'N/A';
            
            $billing_first = $order->get_billing_first_name() ?: '';
            $billing_last = $order->get_billing_last_name() ?: '';
            $customer_name = trim($billing_first . ' ' . $billing_last) ?: 'Guest';

            // Get customer/user information
            $lgl_id = 'N/A';
            $membership_type = 'N/A';
            $subscription_status = 'N/A';
            $membership_start_date = 'N/A';
            $renewal_date = 'N/A';
            $subscription_id = 'N/A';
            
            if ($customer_id && is_numeric($customer_id) && $customer_id > 0) {
                // Validate user exists
                $user = get_user_by('id', $customer_id);
                if ($user) {
                    // Get user meta with fallbacks
                    $lgl_id = get_user_meta($customer_id, 'lgl_constituent_id', true) ?: 
                             get_user_meta($customer_id, 'lgl_id', true) ?: 'N/A';
                    
                    $membership_type = get_user_meta($customer_id, 'user-membership-type', true) ?: 'N/A';
                    $subscription_status = get_user_meta($customer_id, 'user-subscription-status', true) ?: 'N/A';
                    
                    // Format dates safely
                    $membership_start_raw = get_user_meta($customer_id, 'user-membership-start-date', true);
                    if (!empty($membership_start_raw) && strtotime($membership_start_raw)) {
                        $membership_start_date = date('M j, Y', strtotime($membership_start_raw));
                    }
                    
                    $renewal_date_raw = get_user_meta($customer_id, 'user-membership-renewal-date', true);
                    if (!empty($renewal_date_raw) && strtotime($renewal_date_raw)) {
                        $renewal_date = date('M j, Y', strtotime($renewal_date_raw));
                    }
                    
                    $subscription_id = get_user_meta($customer_id, 'user-subscription-id', true) ?: 'N/A';
                }
            }

            return [
                'order_id' => $order_id,
                'date' => $date_str,
                'customer_name' => $customer_name,
                'customer_email' => $order->get_billing_email() ?: 'N/A',
                'customer_phone' => $order->get_billing_phone() ?: 'N/A',
                'total' => $order->get_total(),
                'item_count' => $order->get_item_count(),
                'status' => $order->get_status(),
                'lgl_id' => $lgl_id,
                'membership_type' => $membership_type,
                'subscription_status' => $subscription_status,
                'membership_start_date' => $membership_start_date,
                'renewal_date' => $renewal_date,
                'subscription_id' => $subscription_id,
                'items' => $order->get_items(),
                'customer_id' => $customer_id
            ];
            
        } catch (\Exception $e) {
            error_log('LGL Utilities: Error formatting order data for order #' . ($order->get_id() ?? 'unknown') . ' - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get user's LGL constituent ID with fallbacks
     * 
     * @param int $user_id WordPress user ID
     * @return string LGL constituent ID or 'N/A'
     */
    public static function getUserLglId($user_id) {
        if (!$user_id || !is_numeric($user_id)) {
            return 'N/A';
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return 'N/A';
        }
        
        // Try multiple meta keys for LGL ID
        $lgl_meta_keys = ['lgl_constituent_id', 'lgl_id', 'lgl_user_id'];
        
        foreach ($lgl_meta_keys as $meta_key) {
            $lgl_id = get_user_meta($user_id, $meta_key, true);
            if (!empty($lgl_id)) {
                return $lgl_id;
            }
        }
        
        return 'N/A';
    }
    
    /**
     * Format price with WooCommerce or fallback formatting
     * 
     * @param float $amount Price amount
     * @return string Formatted price
     */
    public static function formatPrice($amount) {
        if (function_exists('wc_price')) {
            return wc_price($amount);
        }
        
        return '$' . number_format((float)$amount, 2);
    }
    
    /**
     * Validate date string and return formatted date
     * 
     * @param string $date_string Date string to validate and format
     * @param string $format Output format (default: 'M j, Y')
     * @return string Formatted date or 'N/A'
     */
    public static function formatDate($date_string, $format = 'M j, Y') {
        if (empty($date_string)) {
            return 'N/A';
        }
        
        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return 'N/A';
        }
        
        return date($format, $timestamp);
    }
    
    /**
     * Get environment information for debugging
     * 
     * @return array Environment information
     */
    public static function getEnvironmentInfo() {
        return [
            'wp_version' => get_bloginfo('version'),
            'wc_version' => class_exists('WooCommerce') ? WC()->version : 'Not installed',
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'site_url' => get_site_url(),
            'is_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'timezone' => wp_timezone_string(),
        ];
    }
    
    /**
     * Log environment information for debugging
     */
    public static function logEnvironmentInfo() {
        $env_info = static::getEnvironmentInfo();
        error_log('LGL Utilities Environment: ' . json_encode($env_info, JSON_PRETTY_PRINT));
    }
    
    /**
     * Sanitize and validate email address
     * 
     * @param string $email Email address to validate
     * @return string|false Sanitized email or false if invalid
     */
    public static function validateEmail($email) {
        $sanitized = sanitize_email($email);
        return is_email($sanitized) ? $sanitized : false;
    }
    
    /**
     * Generate secure cache key
     * 
     * @param string $base_key Base key string
     * @param array $params Additional parameters to include in key
     * @return string Secure cache key
     */
    public static function generateCacheKey($base_key, $params = []) {
        $key_data = array_merge([$base_key], $params);
        return 'lgl_' . md5(serialize($key_data));
    }
    
    /**
     * Register backward compatibility functions
     */
    private static function registerBackwardCompatibility() {
        // Make utilities available globally for backward compatibility
        if (!function_exists('get_filtered_orders')) {
            function get_filtered_orders($start_datetime, $end_datetime) {
                return Utilities::getFilteredOrders($start_datetime, $end_datetime);
            }
        }

        if (!function_exists('format_order_data')) {
            function format_order_data($order) {
                return Utilities::formatOrderData($order);
            }
        }
    }
}
