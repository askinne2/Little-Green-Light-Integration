<?php
/**
 * Settings Handler
 * 
 * Handles saving and processing of LGL settings forms.
 * Provides native WordPress options storage for all LGL settings.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Connection;

/**
 * SettingsHandler Class
 * 
 * Manages all LGL settings form processing and storage
 */
class SettingsHandler {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Connection service
     * 
     * @var Connection
     */
    private Connection $connection;
    
    /**
     * Settings option name
     */
    const OPTION_NAME = 'lgl_integration_settings';
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param Connection $connection Connection service
     */
    public function __construct(Helper $helper, Connection $connection) {
        $this->helper = $helper;
        $this->connection = $connection;
    }
    
    /**
     * Initialize settings handler
     */
    public function initialize(): void {
       
        
        // Register settings
        add_action('admin_init', [$this, 'registerSettings']);
        
        // Handle form submissions
        add_action('admin_post_lgl_save_api_settings', [$this, 'handleApiSettings']);
        add_action('admin_post_lgl_save_membership_settings', [$this, 'handleMembershipSettings']);
        add_action('admin_post_lgl_save_debug_settings', [$this, 'handleDebugSettings']);
        
        //  error_log('LGL SettingsHandler: admin_post hooks registered - lgl_save_api_settings, lgl_save_membership_settings, lgl_save_debug_settings');
        
        // Remove any existing handlers first to avoid conflicts
        remove_all_actions('wp_ajax_lgl_test_connection');
        remove_all_actions('wp_ajax_lgl_test_api_connection');
        
        // AJAX handlers for connection testing - using closure to ensure proper callback
        add_action('wp_ajax_lgl_test_connection', function() {
            error_log('🔥🔥🔥 AJAX ACTION lgl_test_connection TRIGGERED! Calling handleConnectionTest...');
            $this->handleConnectionTest();
        }, 10);
        add_action('wp_ajax_lgl_test_api_connection', function() {
            error_log('🔥🔥🔥 AJAX ACTION lgl_test_api_connection TRIGGERED!');
            $this->handleConnectionTest();
        }, 10);
        add_action('wp_ajax_nopriv_lgl_test_connection', function() {
            error_log('🔥🔥🔥 AJAX ACTION lgl_test_connection (nopriv) TRIGGERED!');
            $this->handleConnectionTest();
        }, 10);
        
        // Debug: Test if AJAX action is registered
        // error_log('LGL SettingsHandler: AJAX handlers registered for actions: wp_ajax_lgl_test_connection, wp_ajax_lgl_test_api_connection');
        
        // Debug handlers removed - connection test is now working properly
        
        // error_log('LGL SettingsHandler: All hooks registered');
    }
    
    /**
     * Register WordPress settings
     */
    public function registerSettings(): void {
        register_setting('lgl_integration_settings', self::OPTION_NAME, [
            'sanitize_callback' => [$this, 'sanitizeSettings']
        ]);
    }
    
    /**
     * Get all settings
     */
    public function getSettings(): array {
        $defaults = [
            'api_url' => '',
            'api_key' => '',
            'debug_mode' => false,
            'test_mode' => false,
            'membership_levels' => []
        ];
        
        return wp_parse_args(get_option(self::OPTION_NAME, []), $defaults);
    }
    
    /**
     * Update settings
     */
    public function updateSettings(array $settings): bool {
        $current = $this->getSettings();
        $updated = array_merge($current, $settings);
        
        $result = update_option(self::OPTION_NAME, $updated);
        
        // Clear cache after successful update
        if ($result) {
            $this->clearSettingsCache();
        }
        
        return $result;
    }
    
    /**
     * Handle API settings form submission
     */
    public function handleApiSettings(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'lgl_api_settings')) {
            wp_die('Insufficient permissions');
        }
        
        $api_url = sanitize_url($_POST['lgl_api_url']);
        $api_key = sanitize_text_field($_POST['lgl_api_key']);
        
        // Validate required fields
        if (empty($api_url) || empty($api_key)) {
            $this->redirectWithMessage('error', 'API URL and API Key are required.');
            return;
        }
        
        // Validate URL format
        if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
            $this->redirectWithMessage('error', 'Please enter a valid API URL.');
            return;
        }
        
        // Save settings
        $settings = [
            'api_url' => rtrim($api_url, '/'),
            'api_key' => $api_key
        ];
        
        if ($this->updateSettings($settings)) {
            $this->redirectWithMessage('updated', 'API settings saved successfully!');
        } else {
            $this->redirectWithMessage('error', 'Failed to save API settings.');
        }
    }
    
    /**
     * Handle membership settings form submission
     */
    public function handleMembershipSettings(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'lgl_membership_settings')) {
            wp_die('Insufficient permissions');
        }
        
        $membership_levels = [];
        
        // Process membership levels if provided
        if (isset($_POST['membership_levels']) && is_array($_POST['membership_levels'])) {
            foreach ($_POST['membership_levels'] as $index => $level) {
                if (empty($level['level_name']) || empty($level['lgl_membership_level_id'])) {
                    continue; // Skip incomplete entries
                }
                
                $membership_levels[] = [
                    'level_name' => sanitize_text_field($level['level_name']),
                    'level_slug' => sanitize_title($level['level_slug'] ?: $level['level_name']),
                    'lgl_membership_level_id' => intval($level['lgl_membership_level_id']),
                    'price' => floatval($level['price'] ?? 0)
                ];
            }
        }
        
        // Save settings
        $settings = [
            'membership_levels' => $membership_levels
        ];
        
        if ($this->updateSettings($settings)) {
            $count = count($membership_levels);
            $this->redirectWithMessage('updated', "Membership settings saved successfully! ($count levels configured)");
        } else {
            $this->redirectWithMessage('error', 'Failed to save membership settings.');
        }
    }
    
    /**
     * Handle debug settings form submission
     */
    public function handleDebugSettings(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'lgl_debug_settings')) {
            wp_die('Insufficient permissions');
        }
        
        $debug_mode = isset($_POST['lgl_debug_mode']);
        $test_mode = isset($_POST['lgl_test_mode']);
        
        // Save settings
        $settings = [
            'debug_mode' => $debug_mode,
            'test_mode' => $test_mode
        ];
        
        if ($this->updateSettings($settings)) {
            $this->redirectWithMessage('updated', 'Debug settings saved successfully!');
        } else {
            $this->redirectWithMessage('error', 'Failed to save debug settings.');
        }
    }
    
    /**
     * Handle connection test AJAX request
     */
    public function handleConnectionTest(): void {
        error_log('🚨🚨🚨 LGL SettingsHandler: handleConnectionTest() METHOD CALLED! 🚨🚨🚨');
        error_log('LGL SettingsHandler: Connection test requested');
        error_log('LGL SettingsHandler: POST data: ' . print_r($_POST, true));
        
        // Check permissions - be more lenient for AJAX requests
        if (!current_user_can('manage_options') && !current_user_can('edit_posts')) {
            error_log('LGL SettingsHandler: User lacks sufficient permissions');
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lgl_admin_nonce')) {
            error_log('LGL SettingsHandler: Nonce verification failed');
            wp_send_json_error('Nonce verification failed');
            return;
        }
        
        // Get API credentials - either from form data or saved settings
        $api_url = $_POST['api_url'] ?? '';
        $api_key = $_POST['api_key'] ?? '';
        
        // If not provided in request, get from saved settings
        if (empty($api_url) || empty($api_key)) {
            $settings = $this->getSettings();
            $api_url = $api_url ?: $settings['api_url'];
            $api_key = $api_key ?: $settings['api_key'];
        }
        
        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error([
                'message' => 'API URL and API Key are required for testing. Please enter them in the form above.'
            ]);
            return;
        }
        
        error_log('LGL SettingsHandler: Testing with URL: ' . $api_url . ', Key length: ' . strlen($api_key));
        
        // Test the connection
        try {
            $test_result = $this->testApiConnection($api_url, $api_key);
            
            if ($test_result['success']) {
                wp_send_json_success($test_result);
            } else {
                wp_send_json_error($test_result);
            }
        } catch (\Exception $e) {
            error_log('LGL SettingsHandler: Connection test exception: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Connection test failed: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Test API connection
     */
    private function testApiConnection(string $api_url, string $api_key): array {
        // Try multiple endpoints to find the correct one
        $endpoints_to_test = [
            '/constituents.json?limit=1',
            '/constituents',
            '/api/v1/constituents.json?limit=1',
            '/api/v1/constituents',
            '', // Just the base URL
        ];
        
        $base_url = rtrim($api_url, '/');
        error_log('LGL SettingsHandler: Testing API connection with base URL: ' . $base_url);
        
        foreach ($endpoints_to_test as $endpoint) {
            $test_url = $base_url . $endpoint;
            error_log('LGL SettingsHandler: Trying endpoint: ' . $test_url);
            
            $response = wp_remote_get($test_url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'timeout' => 10
            ]);
            
            if (is_wp_error($response)) {
                error_log('LGL SettingsHandler: WP Error for ' . $test_url . ': ' . $response->get_error_message());
                continue; // Try next endpoint
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            error_log('LGL SettingsHandler: ' . $test_url . ' - Status: ' . $status_code . ', Body length: ' . strlen($body));
            
            if ($status_code === 200) {
                $data = json_decode($body, true);
                return [
                    'success' => true,
                    'message' => "Connection successful! Working endpoint: $test_url",
                    'endpoint' => $test_url,
                    'api_version' => 'v1',
                    'status_code' => $status_code,
                    'response_preview' => substr($body, 0, 200) . '...'
                ];
            } elseif ($status_code === 401) {
                // Authentication error - API key might be wrong
                return [
                    'success' => false,
                    'message' => "Authentication failed (HTTP 401). Please check your API key.",
                    'status_code' => $status_code,
                    'endpoint_tested' => $test_url
                ];
            } elseif ($status_code === 403) {
                // Forbidden - API key might not have permission
                return [
                    'success' => false,
                    'message' => "Access forbidden (HTTP 403). Your API key might not have sufficient permissions.",
                    'status_code' => $status_code,
                    'endpoint_tested' => $test_url
                ];
            }
            
            // Log other status codes but continue trying
            error_log('LGL SettingsHandler: ' . $test_url . ' returned HTTP ' . $status_code . ': ' . substr($body, 0, 100));
        }
        
        // If we get here, none of the endpoints worked
        return [
            'success' => false,
            'message' => "Could not find a working API endpoint. Tried: " . implode(', ', array_map(function($ep) use ($base_url) { return $base_url . $ep; }, $endpoints_to_test)),
            'endpoints_tested' => count($endpoints_to_test)
        ];
    }
    
    /**
     * Sanitize settings
     */
    public function sanitizeSettings($input): array {
        $sanitized = [];
        
        if (isset($input['api_url'])) {
            $sanitized['api_url'] = sanitize_url($input['api_url']);
        }
        
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        if (isset($input['debug_mode'])) {
            $sanitized['debug_mode'] = (bool) $input['debug_mode'];
        }
        
        if (isset($input['test_mode'])) {
            $sanitized['test_mode'] = (bool) $input['test_mode'];
        }
        
        if (isset($input['membership_levels']) && is_array($input['membership_levels'])) {
            $sanitized['membership_levels'] = array_map(function($level) {
                return [
                    'level_name' => sanitize_text_field($level['level_name'] ?? ''),
                    'level_slug' => sanitize_title($level['level_slug'] ?? ''),
                    'lgl_membership_level_id' => intval($level['lgl_membership_level_id'] ?? 0),
                    'price' => floatval($level['price'] ?? 0)
                ];
            }, $input['membership_levels']);
        }
        
        return $sanitized;
    }
    
    /**
     * Redirect with admin message
     */
    private function redirectWithMessage(string $type, string $message): void {
        $redirect_url = add_query_arg([
            'page' => 'lgl-settings', // Use the correct page slug
            'message' => urlencode($message),
            'type' => $type
        ], admin_url('admin.php'));
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Clear settings cache
     */
    private function clearSettingsCache(): void {
        // Clear any object cache
        wp_cache_delete(self::OPTION_NAME, 'options');
        
        // Clear ApiSettings cache if available
        if (class_exists('\UpstateInternational\LGL\LGL\ApiSettings')) {
            $apiSettings = \UpstateInternational\LGL\LGL\ApiSettings::getInstance();
            if (method_exists($apiSettings, 'clearCache')) {
                $apiSettings->clearCache();
            }
        }
    }
    
    /**
     * Get setting value
     */
    public function getSetting(string $key, $default = null) {
        $settings = $this->getSettings();
        return $settings[$key] ?? $default;
    }
    
    /**
     * Check if debug mode is enabled
     */
    public function isDebugMode(): bool {
        return (bool) $this->getSetting('debug_mode', false);
    }
    
    /**
     * Check if test mode is enabled
     */
    public function isTestMode(): bool {
        return (bool) $this->getSetting('test_mode', false);
    }
}
?>