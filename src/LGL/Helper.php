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
     * Debug mode flag
     */
    const DEBUG_MODE = false;
    
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
        if (static::DEBUG_MODE) {
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
        if (!static::DEBUG_MODE) {
            return;
        }
        
        $output = '<h6 style="color: red;">' . esc_html($message) . '</h6>';
        
        if ($data !== null) {
            $output .= '<pre>' . esc_html(print_r($data, true)) . '</pre>';
        }
        
        echo $output;
        
        // Also log to error log
        $log_message = $message;
        if ($data !== null) {
            $log_message .= ' ' . print_r($data, true);
        }
        
        $this->log($log_message, 'debug');
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
        return static::DEBUG_MODE || (defined('WP_DEBUG') && WP_DEBUG);
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
}

// Maintain backward compatibility
if (!function_exists('lgl_helper')) {
    function lgl_helper(): Helper {
        return Helper::getInstance();
    }
}
