<?php
/**
 * LGL Helper Class
 * 
 * Provides helper functions and utilities for the LGL API integration.
 * Handles logging, debugging, and common operations.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

/**
 * Helper Class
 * 
 * Manages helper functions and utilities for LGL API operations
 */
class Helper {
    
    /**
     * Class instance
     * 
     * @var Helper|null
     */
    private static $instance = null;
    
    /**
     * Log file path
     */
    const LOG_FILE = 'logs/lgl-api.log';
    
    /**
     * Debug mode flag (now dynamically determined)
     */
    // Removed hardcoded constant - now uses ApiSettings checkbox
    
    /**
     * Get instance
     * 
     * @return Helper
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct() {
        // Initialize helper
    }
    
    /**
     * Log messages to file and error log
     * 
     * @param string $message Message to log
     * @param string $level Log level (info, warning, error)
     */
    public function log(string $message, string $level = 'info'): void {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        // Log to WordPress error log
        error_log("LGL Helper [{$level}]: {$message}");
        
        // Log to file if debug mode is enabled
        if ($this->isDebugMode()) {
            $log_file = plugin_dir_path(__DIR__) . static::LOG_FILE;
            $log_dir = dirname($log_file);
            
            // Create log directory if it doesn't exist
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
            }
            
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Debug output with conditional display
     * 
     * @param string $message Debug message
     * @param mixed $data Optional data to display
     */
    public function debug(string $message, $data = null): void {
        if (!$this->isDebugMode()) {
            return;
        }
        
        // Format the complete log message
        $log_message = $message;
        if ($data !== null) {
            $log_message .= ' ' . print_r($data, true);
        }
        
        // Only log once to WordPress error log (no duplicate through $this->log())
        error_log("LGL Debug: {$log_message}");
        
        // Fire WordPress action for shortcodes to capture debug messages
        do_action('lgl_debug_message', $message, $data);
        
        // Also display in browser if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $output = '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 5px 0; border-radius: 3px; font-family: monospace; font-size: 12px;">';
            $output .= '<strong style="color: #856404;">🔍 LGL Debug:</strong> ' . esc_html($message);
            
            if ($data !== null) {
                $output .= '<pre style="margin-top: 10px; background: #f8f9fa; padding: 10px; overflow-x: auto; max-height: 200px;">';
                $output .= esc_html(print_r($data, true));
                $output .= '</pre>';
            }
            
            $output .= '</div>';
            echo $output;
        }
        
        // Also log to file if enabled (but don't duplicate in error.log)
        if ($this->isDebugMode()) {
            $timestamp = current_time('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] [debug] {$log_message}" . PHP_EOL;
            $log_file = plugin_dir_path(__DIR__) . self::LOG_FILE;
            $log_dir = dirname($log_file);
            
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
            }
            
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Validate email address
     * 
     * @param string $email Email to validate
     * @return bool True if valid email
     */
    public function validateEmail(string $email): bool {
        return is_email($email);
    }
    
    /**
     * Sanitize phone number
     * 
     * @param string $phone Phone number to sanitize
     * @return string Sanitized phone number
     */
    public function sanitizePhone(string $phone): string {
        // Remove all non-numeric characters except + and -
        $sanitized = preg_replace('/[^0-9+\-]/', '', $phone);
        
        // Ensure it's not empty and has reasonable length
        if (empty($sanitized) || strlen($sanitized) < 10) {
            return '';
        }
        
        return $sanitized;
    }
    
    /**
     * Format date for LGL API
     * 
     * @param string|int $date Date string or timestamp
     * @param string $format Output format
     * @return string Formatted date
     */
    public function formatDateForApi($date, string $format = 'Y-m-d'): string {
        if (empty($date)) {
            return '';
        }
        
        // Handle Unix timestamp
        if (is_numeric($date)) {
            return date($format, $date);
        }
        
        // Handle date string
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }
        
        return date($format, $timestamp);
    }
    
    /**
     * Generate unique identifier
     * 
     * @param string $prefix Optional prefix
     * @return string Unique identifier
     */
    public function generateUniqueId(string $prefix = 'lgl'): string {
        return $prefix . '_' . uniqid() . '_' . time();
    }
    
    /**
     * Validate required fields
     * 
     * @param array $data Data to validate
     * @param array $required_fields Required field names
     * @return array Array of missing fields
     */
    public function validateRequiredFields(array $data, array $required_fields): array {
        $missing = [];
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $missing[] = $field;
            }
        }
        
        return $missing;
    }
    
    /**
     * Clean and sanitize data for API submission
     * 
     * @param array $data Raw data
     * @return array Cleaned data
     */
    public function sanitizeApiData(array $data): array {
        $cleaned = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $cleaned[$key] = sanitize_text_field($value);
            } elseif (is_email($value)) {
                $cleaned[$key] = sanitize_email($value);
            } elseif (is_array($value)) {
                $cleaned[$key] = $this->sanitizeApiData($value);
            } else {
                $cleaned[$key] = $value;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Check if string is valid JSON
     * 
     * @param string $string String to check
     * @return bool True if valid JSON
     */
    public function isValidJson(string $string): bool {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    /**
     * Parse API response
     * 
     * @param string $response API response string
     * @return array|null Parsed response or null on failure
     */
    public function parseApiResponse(string $response): ?array {
        if (!$this->isValidJson($response)) {
            $this->log('Invalid JSON response: ' . $response, 'error');
            return null;
        }
        
        $decoded = json_decode($response, true);
        
        if ($decoded === null) {
            $this->log('Failed to decode JSON response', 'error');
            return null;
        }
        
        return $decoded;
    }
    
    /**
     * Build query string from array
     * 
     * @param array $params Parameters array
     * @return string Query string
     */
    public function buildQueryString(array $params): string {
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    
    /**
     * Get current user's LGL ID
     * 
     * @param int|null $user_id User ID (defaults to current user)
     * @return string|null LGL ID or null if not found
     */
    public function getCurrentUserLglId(?int $user_id = null): ?string {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return null;
        }
        
        // Try multiple meta keys for LGL ID
        $lgl_meta_keys = ['lgl_constituent_id', 'lgl_id', 'lgl_user_id'];
        
        foreach ($lgl_meta_keys as $meta_key) {
            $lgl_id = get_user_meta($user_id, $meta_key, true);
            if (!empty($lgl_id)) {
                return $lgl_id;
            }
        }
        
        return null;
    }
    
    /**
     * Check if user has valid LGL connection
     * 
     * @param int|null $user_id User ID (defaults to current user)
     * @return bool True if user has LGL connection
     */
    public function userHasLglConnection(?int $user_id = null): bool {
        return $this->getCurrentUserLglId($user_id) !== null;
    }
    
    /**
     * Format currency amount
     * 
     * @param float $amount Amount to format
     * @param string $currency Currency code
     * @return string Formatted currency
     */
    public function formatCurrency(float $amount, string $currency = 'USD'): string {
        return number_format($amount, 2, '.', ',');
    }
    
    /**
     * Get WordPress user by LGL ID
     * 
     * @param string $lgl_id LGL constituent ID
     * @return \WP_User|null WordPress user or null if not found
     */
    public function getUserByLglId(string $lgl_id): ?\WP_User {
        $lgl_meta_keys = ['lgl_constituent_id', 'lgl_id', 'lgl_user_id'];
        
        foreach ($lgl_meta_keys as $meta_key) {
            $users = get_users([
                'meta_key' => $meta_key,
                'meta_value' => $lgl_id,
                'number' => 1
            ]);
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        return null;
    }
    
    /**
     * Check if API is in debug mode
     * 
     * @return bool True if in debug mode
     */
    public function isDebugMode(): bool {
        // Check WordPress option directly to avoid circular dependency
        // (ApiSettings::getInstance() can trigger infinite loop during initialization)
        $settings = get_option('lgl_integration_settings', []);
        if (isset($settings['debug_mode']) && $settings['debug_mode']) {
            return true;
        }
        
        // Fallback to Carbon Fields if new settings not found
        if (function_exists('carbon_get_theme_option')) {
            if (carbon_get_theme_option('lgl_debug_mode')) {
                return true;
            }
        }
        
        // Final fallback to WP_DEBUG
        return defined('WP_DEBUG') && WP_DEBUG;
    }
    
    /**
     * Get error message from API response
     * 
     * @param array $response API response
     * @return string Error message
     */
    public function getApiErrorMessage(array $response): string {
        if (isset($response['error'])) {
            return $response['error'];
        }
        
        if (isset($response['message'])) {
            return $response['message'];
        }
        
        if (isset($response['errors']) && is_array($response['errors'])) {
            return implode(', ', $response['errors']);
        }
        
        return 'Unknown API error';
    }
    
    /**
     * Check if API response indicates success
     * 
     * @param array $response API response
     * @return bool True if successful
     */
    public function isApiResponseSuccessful(array $response): bool {
        // Check for explicit success indicators
        if (isset($response['success']) && $response['success']) {
            return true;
        }
        
        if (isset($response['status']) && $response['status'] === 'success') {
            return true;
        }
        
        // Check for absence of error indicators
        if (!isset($response['error']) && !isset($response['errors'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Log API request/response for debugging
     * 
     * @param string $endpoint API endpoint
     * @param array $request_data Request data
     * @param array $response_data Response data
     */
    public function logApiTransaction(string $endpoint, array $request_data, array $response_data): void {
        if (!$this->isDebugMode()) {
            return;
        }
        
        $log_data = [
            'endpoint' => $endpoint,
            'timestamp' => current_time('Y-m-d H:i:s'),
            'request' => $request_data,
            'response' => $response_data
        ];
        
        $this->log('API Transaction: ' . json_encode($log_data), 'debug');
    }
    
    /**
     * Get plugin version
     * 
     * @return string Plugin version
     */
    public function getPluginVersion(): string {
        return '2.0.0';
    }
    
    /**
     * Get plugin directory path
     * 
     * @return string Plugin directory path
     */
    public function getPluginDir(): string {
        return plugin_dir_path(__DIR__ . '/../lgl-api.php');
    }
    
    /**
     * Get plugin URL
     * 
     * @return string Plugin URL
     */
    public function getPluginUrl(): string {
        return plugin_dir_url(__DIR__ . '/../lgl-api.php');
    }
    
    /**
     * Convert WooCommerce membership product name to LGL membership name
     * 
     * @param string $wc_product_name WooCommerce product name
     * @return string LGL membership name
     */
    public function uiMembershipWcNameToLgl(string $wc_product_name): string {
        $this->debug('🔄 Helper: Converting WC name to LGL name', [
            'input' => $wc_product_name
        ]);
        
        // Map WooCommerce variation names to LGL membership names
        $name_mapping = [
            // NEW membership names (primary)
            'Member' => 'Member',
            'Supporter' => 'Supporter', 
            'Patron' => 'Patron',
            'Family Member' => 'Family Member',
            
            // LEGACY names (sunset in 1 year - keep for backward compatibility)
            'Membership - Individual' => 'Individual Membership',
            'Membership - Family' => 'Family Membership', 
            'Membership - Patron' => 'Patron Membership',
            'Membership - Patron Family' => 'Patron Family Membership',
            'Daily Membership - Daily' => 'Daily Plan',
            
            // Legacy fallbacks (convert old names to new)
            'Individual Membership' => 'Member',
            'Family Membership' => 'Member',
            'Patron Membership' => 'Patron',
            'Patron Family Membership' => 'Patron',
        ];
        
        if (isset($name_mapping[$wc_product_name])) {
            $lgl_name = $name_mapping[$wc_product_name];
            $this->debug('✅ Helper: Name mapping found', [
                'wc_name' => $wc_product_name,
                'lgl_name' => $lgl_name
            ]);
            return $lgl_name;
        }
        
        $this->debug('⚠️ Helper: No name mapping found, returning original', [
            'wc_name' => $wc_product_name
        ]);
        return $wc_product_name;
    }
    
    /**
     * Convert membership price to membership name (Legacy compatibility)
     * 
     * @param float $price Membership price
     * @return string Membership name
     */
    public function uiMembershipPriceToName(float $price): string {
        $this->debug('🔄 Helper: Converting price to membership name', [
            'price' => $price
        ]);
        
        // Price-to-name mapping (NEW system + legacy for backward compatibility)
        $price_mapping = [
            // NEW pricing structure
            75 => 'Member',
            150 => 'Supporter',
            500 => 'Patron',
            25 => 'Family Member',
            
            // LEGACY pricing (sunset in 1 year - keep for backward compatibility)
            100 => 'Family Membership',
            200 => 'Patron Membership', 
            250 => 'Patron Family Membership',
            5 => 'Daily Plan'
        ];
        
        if (isset($price_mapping[$price])) {
            $membership_name = $price_mapping[$price];
            $this->debug('✅ Helper: Price mapping found', [
                'price' => $price,
                'membership_name' => $membership_name
            ]);
            return $membership_name;
        }
        
        $this->debug('⚠️ Helper: No price mapping found', [
            'price' => $price,
            'available_prices' => array_keys($price_mapping)
        ]);
        return '';
    }
    
    /**
     * Convert membership name to price (Legacy compatibility)
     * 
     * @param string $membership_name Membership name
     * @return float Membership price
     */
    public function uiMembershipNameToPrice(string $membership_name): float {
        $this->debug('🔄 Helper: Converting membership name to price', [
            'membership_name' => $membership_name
        ]);
        
        // Name-to-price mapping (NEW system + legacy for backward compatibility)
        $name_mapping = [
            // NEW membership names
            'Member' => 75,
            'Supporter' => 150,
            'Patron' => 500,
            'Family Member' => 25,
            
            // LEGACY names (sunset in 1 year - keep for backward compatibility)
            'Individual Membership' => 75,
            'Family Membership' => 100,
            'Patron Membership' => 200,
            'Patron Family Membership' => 250,
            'Daily Plan' => 5
        ];
        
        if (isset($name_mapping[$membership_name])) {
            $price = $name_mapping[$membership_name];
            $this->debug('✅ Helper: Name-to-price mapping found', [
                'membership_name' => $membership_name,
                'price' => $price
            ]);
            return $price;
        }
        
        $this->debug('⚠️ Helper: No name-to-price mapping found', [
            'membership_name' => $membership_name,
            'available_names' => array_keys($name_mapping)
        ]);
        return 0;
    }
    
    /**
     * Change user role (Legacy compatibility)
     * 
     * @param int $user_id User ID
     * @param string $old_role Old role to remove
     * @param string $new_role New role to add
     * @return bool Success status
     */
    public function changeUserRole(int $user_id, string $old_role, string $new_role): bool {
        $this->debug('👤 Helper: Changing user role', [
            'user_id' => $user_id,
            'old_role' => $old_role,
            'new_role' => $new_role
        ]);
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            $this->debug('❌ Helper: User not found for role change', $user_id);
            return false;
        }
        
        // Remove old role
        $user->remove_role($old_role);
        
        // Add new role
        $user->add_role($new_role);
        
        $this->debug('✅ Helper: User role changed successfully', [
            'user_id' => $user_id,
            'from' => $old_role,
            'to' => $new_role,
            'current_roles' => $user->roles
        ]);
        
        return true;
    }
    
    /**
     * Get actual number of family members for a user (from JetEngine relationships)
     * 
     * Queries JetEngine relation ID 24 to get the real count of connected family members.
     * This is the source of truth - not the user_used_family_slots meta field.
     * 
     * @param int $parent_uid Parent user ID
     * @return int Number of actual family member relationships
     */
    public function getActualUsedFamilySlots(int $parent_uid): int {
        if (!function_exists('jet_engine')) {
            $this->debug('Helper: JetEngine not available, cannot query family relationships');
            return 0;
        }
        
        try {
            $relation = \jet_engine()->relations->get_active_relations(24);
            if (!$relation) {
                $this->debug('Helper: Could not get JetEngine relation 24');
                return 0;
            }
            
            $children = $relation->get_children($parent_uid, 'ids');
            $count = is_array($children) ? count($children) : 0;
            
            $this->debug('Helper: Queried actual family slots from JetEngine', [
                'parent_uid' => $parent_uid,
                'actual_count' => $count,
                'children_ids' => $children
            ]);
            
            return $count;
            
        } catch (\Exception $e) {
            $this->debug('Helper: Error querying JetEngine relationships', [
                'error' => $e->getMessage(),
                'parent_uid' => $parent_uid
            ]);
            return 0;
        }
    }
    
    /**
     * Get available family slots for a user
     * 
     * Calculates: total_purchased - actual_used_from_jetengine
     * 
     * @param int $parent_uid Parent user ID
     * @return int Available slots (can be negative if data is inconsistent)
     */
    public function getAvailableFamilySlots(int $parent_uid): int {
        $total_purchased = (int) get_user_meta($parent_uid, 'user_total_family_slots_purchased', true);
        $actual_used = $this->getActualUsedFamilySlots($parent_uid);
        
        $available = $total_purchased - $actual_used;
        
        $this->debug('Helper: Calculated available family slots', [
            'parent_uid' => $parent_uid,
            'total_purchased' => $total_purchased,
            'actual_used' => $actual_used,
            'available' => $available
        ]);
        
        return $available;
    }
    
    /**
     * Sync user_used_family_slots meta with actual JetEngine count
     * 
     * Updates the meta field to match reality (for backwards compatibility)
     * 
     * @param int $parent_uid Parent user ID
     * @return void
     */
    public function syncUsedFamilySlotsMeta(int $parent_uid): void {
        $actual_count = $this->getActualUsedFamilySlots($parent_uid);
        update_user_meta($parent_uid, 'user_used_family_slots', $actual_count);
        
        $this->debug('Helper: Synced user_used_family_slots meta', [
            'parent_uid' => $parent_uid,
            'synced_count' => $actual_count
        ]);
    }
    
    /**
     * Hook into JetEngine relationship deletion to auto-sync slots
     * 
     * This ensures slots are synced even if relationships are deleted outside the plugin
     * 
     * @return void
     */
    public function hookJetEngineRelationshipDeletion(): void {
        // Hook into JetEngine's relationship deletion
        add_action('jet-engine/relations/after-delete', function($relation_id, $parent_id, $child_id) {
            if ($relation_id == 24) { // Family relationship ID
                $this->syncUsedFamilySlotsMeta($parent_id);
                
                $total_purchased = (int) get_user_meta($parent_id, 'user_total_family_slots_purchased', true);
                $actual_used = $this->getActualUsedFamilySlots($parent_id);
                $new_available = $total_purchased - $actual_used;
                update_user_meta($parent_id, 'user_available_family_slots', max(0, $new_available));
                
                $this->debug('Helper: Auto-synced slots after JetEngine relationship deletion', [
                    'parent_id' => $parent_id,
                    'child_id' => $child_id,
                    'total_purchased' => $total_purchased,
                    'actual_used' => $actual_used,
                    'new_available' => $new_available
                ]);
            }
        }, 10, 3);
    }
}

// Maintain backward compatibility
if (!function_exists('lgl_helper')) {
    function lgl_helper(): Helper {
        return Helper::getInstance();
    }
}
