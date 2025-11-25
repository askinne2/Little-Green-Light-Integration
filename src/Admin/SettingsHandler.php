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
     * Settings Manager service (lazy-loaded)
     * 
     * @var SettingsManager|null
     */
    private ?SettingsManager $settingsManager = null;
    
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
     * Lazy-load SettingsManager to avoid circular dependency
     * 
     * @return SettingsManager|null
     */
    private function getSettingsManager(): ?SettingsManager {
        if ($this->settingsManager === null && function_exists('lgl_get_container')) {
            try {
                $container = lgl_get_container();
                if ($container->has('admin.settings_manager')) {
                    $this->settingsManager = $container->get('admin.settings_manager');
                }
            } catch (\Exception $e) {
                // SettingsManager not available, will use fallback
            }
        }
        return $this->settingsManager;
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
        add_action('admin_post_lgl_save_fund_settings', [$this, 'handleFundSettings']);
        add_action('admin_post_lgl_sync_ids', [$this, 'handleSyncIds']);
        add_action('admin_post_lgl_sync_groups', [$this, 'handleSyncGroups']);
        add_action('admin_post_lgl_save_role_mappings', [$this, 'handleRoleMappings']);
        
        //  error_log('LGL SettingsHandler: admin_post hooks registered - lgl_save_api_settings, lgl_save_membership_settings, lgl_save_debug_settings');
        
        // Remove any existing handlers first to avoid conflicts
        remove_all_actions('wp_ajax_lgl_test_connection');
        remove_all_actions('wp_ajax_lgl_test_api_connection');
        
        // AJAX handlers for connection testing - using closure to ensure proper callback
        add_action('wp_ajax_lgl_test_connection', [$this, 'handleConnectionTest'], 10);
        add_action('wp_ajax_lgl_test_api_connection', [$this, 'handleConnectionTest'], 10);
        add_action('wp_ajax_nopriv_lgl_test_connection', [$this, 'handleConnectionTest'], 10);
        
        // AJAX handlers for importing data from LGL API
        add_action('wp_ajax_lgl_import_membership_levels', [$this, 'handleImportMembershipLevels']);
        add_action('wp_ajax_lgl_import_events', [$this, 'handleImportEvents']);
        add_action('wp_ajax_lgl_import_funds', [$this, 'handleImportFunds']);
        
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
        // Try to delegate to SettingsManager, fallback to direct option access
        $manager = $this->getSettingsManager();
        if ($manager) {
            return $manager->getAll();
        }
        
        // Fallback: direct access with defaults
        $defaults = [
            'api_url' => '',
            'api_key' => '',
            'debug_mode' => false,
            'test_mode' => false,
            'membership_levels' => [],
            'fund_mappings' => [],
            'campaign_mappings' => [],
            'payment_types' => [],
            'relation_endpoints' => []
        ];
        
        return wp_parse_args(get_option(self::OPTION_NAME, []), $defaults);
    }
    
    /**
     * Update settings
     */
    public function updateSettings(array $settings): bool {
        // Try to delegate to SettingsManager, fallback to direct update
        $manager = $this->getSettingsManager();
        if ($manager) {
            return $manager->update($settings);
        }
        
        // Fallback: direct update
        $current = $this->getSettings();
        $updated = array_merge($current, $settings);
        return update_option(self::OPTION_NAME, $updated);
    }
    
    /**
     * Handle API settings form submission
     */
    public function handleApiSettings(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'lgl_api_settings')) {
            wp_die('Insufficient permissions');
        }
        
        // Get environment selection (CRITICAL - must be saved first)
        $environment = isset($_POST['lgl_environment']) ? sanitize_text_field($_POST['lgl_environment']) : 'live';
        if (!in_array($environment, ['dev', 'live'])) {
            $environment = 'live';
        }
        
        // Get environment-specific API credentials
        $dev_api_url = isset($_POST['dev_api_url']) ? sanitize_url($_POST['dev_api_url']) : '';
        $dev_api_key = isset($_POST['dev_api_key']) ? sanitize_text_field($_POST['dev_api_key']) : '';
        $live_api_url = isset($_POST['live_api_url']) ? sanitize_url($_POST['live_api_url']) : '';
        $live_api_key = isset($_POST['live_api_key']) ? sanitize_text_field($_POST['live_api_key']) : '';
        
        // Validate URLs if provided
        if (!empty($dev_api_url) && !filter_var($dev_api_url, FILTER_VALIDATE_URL)) {
            $this->redirectWithMessage('error', 'Please enter a valid Dev API URL.', 'api');
            return;
        }
        
        if (!empty($live_api_url) && !filter_var($live_api_url, FILTER_VALIDATE_URL)) {
            $this->redirectWithMessage('error', 'Please enter a valid Live API URL.', 'api');
            return;
        }
        
        // Save settings - environment must be saved first!
        $settings = [
            'environment' => $environment, // CRITICAL: Save environment first
            'dev_api_url' => !empty($dev_api_url) ? rtrim($dev_api_url, '/') : '',
            'dev_api_key' => $dev_api_key,
            'live_api_url' => !empty($live_api_url) ? rtrim($live_api_url, '/') : '',
            'live_api_key' => $live_api_key
        ];
        
        if ($this->updateSettings($settings)) {
            $this->helper->info('LGL SettingsHandler: API settings saved', [
                'environment' => $environment
            ]);
            $this->redirectWithMessage('updated', 'API settings saved successfully!', 'api');
        } else {
            $this->helper->error('LGL SettingsHandler: Failed to save API settings');
            $this->redirectWithMessage('error', 'Failed to save API settings.', 'api');
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
        
        if (!empty($_POST['import_membership_schema'])) {
            $membership_levels = $this->importMembershipLevelsFromSchema(\sanitize_text_field($_POST['import_membership_schema']));
        }
        
        // Process membership levels if provided
        if (isset($_POST['membership_levels']) && is_array($_POST['membership_levels'])) {
            foreach ($_POST['membership_levels'] as $index => $level) {
                if (empty($level['level_name']) || empty($level['lgl_membership_level_id'])) {
                    continue; // Skip incomplete entries
                }
                
                $membership_levels[] = [
                    'level_name' => \sanitize_text_field($level['level_name']),
                    'level_slug' => \sanitize_title($level['level_slug'] ?: $level['level_name']),
                    'lgl_membership_level_id' => intval($level['lgl_membership_level_id']),
                    'price' => floatval($level['price'] ?? 0)
                ];
            }
        }
        
        $funds = $this->getSettings()['fund_mappings'];
        if (!empty($_POST['import_fund_schema'])) {
            $funds = $this->importFundMappingsFromSchema(\sanitize_text_field($_POST['import_fund_schema']));
        }
        
        $campaigns = $this->getSettings()['campaign_mappings'];
        if (!empty($_POST['import_campaign_schema'])) {
            $campaigns = $this->importCampaignMappingsFromSchema(\sanitize_text_field($_POST['import_campaign_schema']));
        }
        
        $payment_types = $this->getSettings()['payment_types'];
        if (!empty($_POST['import_payment_schema'])) {
            $payment_types = $this->importPaymentTypesFromSchema(\sanitize_text_field($_POST['import_payment_schema']));
        }
        
        $relation_endpoints = $this->getSettings()['relation_endpoints'];
        if (!empty($_POST['import_relation_schema'])) {
            $relation_endpoints = $this->importRelationEndpointsFromSchema(\sanitize_text_field($_POST['import_relation_schema']));
        }
        
        // Save settings
        $settings = [
            'membership_levels' => $membership_levels,
            'fund_mappings' => $funds,
            'campaign_mappings' => $campaigns,
            'payment_types' => $payment_types,
            'relation_endpoints' => $relation_endpoints
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
        $log_level = isset($_POST['lgl_log_level']) ? sanitize_text_field($_POST['lgl_log_level']) : 'debug';
        
        // Validate log level
        $valid_levels = ['error', 'warning', 'info', 'debug'];
        if (!in_array($log_level, $valid_levels)) {
            $log_level = 'debug';
        }
        
        // Save settings
        $settings = [
            'debug_mode' => $debug_mode,
            'test_mode' => $test_mode,
            'log_level' => $log_level
        ];
        
        if ($this->updateSettings($settings)) {
            $this->redirectWithMessage('updated', 'Debug settings saved successfully!');
        } else {
            $this->redirectWithMessage('error', 'Failed to save debug settings.');
        }
    }
    
    /**
     * Handle fund settings form submission
     */
    public function handleFundSettings(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'lgl_fund_settings')) {
            wp_die('Insufficient permissions');
        }
        
        $fund_settings = [
            'fund_id_membership' => isset($_POST['fund_id_membership']) ? (int) $_POST['fund_id_membership'] : 2437,
            'fund_id_language_classes' => isset($_POST['fund_id_language_classes']) ? (int) $_POST['fund_id_language_classes'] : 4132,
            'fund_id_events' => isset($_POST['fund_id_events']) ? (int) $_POST['fund_id_events'] : 4142,
            'fund_id_general' => isset($_POST['fund_id_general']) ? (int) $_POST['fund_id_general'] : 4127,
            'fund_id_family_member_slots' => isset($_POST['fund_id_family_member_slots']) ? (int) $_POST['fund_id_family_member_slots'] : 4147,
        ];
        
        // Validate fund IDs are positive integers
        foreach ($fund_settings as $key => $value) {
            if ($value < 1) {
                $this->redirectWithMessage('error', 'All fund IDs must be positive integers.');
                return;
            }
        }
        
        // Handle cart validation settings
        $cart_validation = [
            'require_membership_for_family_members' => isset($_POST['cart_validation']['require_membership_for_family_members']) ? (bool) $_POST['cart_validation']['require_membership_for_family_members'] : true,
            'max_family_members' => isset($_POST['cart_validation']['max_family_members']) ? max(1, (int) $_POST['cart_validation']['max_family_members']) : 6,
            'allow_guest_family_member_purchase' => isset($_POST['cart_validation']['allow_guest_family_member_purchase']) ? (bool) $_POST['cart_validation']['allow_guest_family_member_purchase'] : false,
        ];
        
        $fund_settings['cart_validation'] = $cart_validation;
        
        if ($this->updateSettings($fund_settings)) {
            $this->redirectWithMessage('updated', 'Fund settings saved successfully!');
        } else {
            $this->redirectWithMessage('error', 'Failed to save fund settings.');
        }
    }
    
    /**
     * Handle sync IDs from LGL API
     * Maps results to environment-specific keys (dev_* or live_*)
     */
    public function handleSyncIds(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'lgl_sync_ids')) {
            wp_die('Security check failed');
        }
        
        $settingsManager = $this->getSettingsManager();
        
        if (!$settingsManager) {
            $this->redirectWithMessage('error', 'Settings manager not available. Please try again.', 'memberships');
            return;
        }
        
        // Get current environment
        $current_env = $settingsManager->getEnvironment();
        $env_prefix = $current_env . '_';
        
        $this->helper->debug('🔍 SettingsHandler: handleSyncIds called', [
            'environment' => $current_env,
            'env_prefix' => $env_prefix
        ]);
        
        $results = $settingsManager->syncFundAndCampaignIds();
        
        if ($results['success']) {
            // Map results to environment-specific keys
            $settings_to_update = [];
            
            // Map funds: fund_id_membership → dev_fund_id_membership or live_fund_id_membership
            foreach ($results['funds'] as $legacy_key => $data) {
                if ($data['id'] > 0) {
                    $env_key = $env_prefix . $legacy_key;
                    $settings_to_update[$env_key] = $data['id'];
                    $this->helper->debug('📥 SettingsHandler: Mapping fund', [
                        'legacy_key' => $legacy_key,
                        'env_key' => $env_key,
                        'fund_id' => $data['id'],
                        'fund_name' => $data['name']
                    ]);
                }
            }
            
            // Map campaigns: campaign_id_membership → dev_campaign_id_membership or live_campaign_id_membership
            foreach ($results['campaigns'] as $legacy_key => $data) {
                if ($data['id'] > 0) {
                    $env_key = $env_prefix . $legacy_key;
                    $settings_to_update[$env_key] = $data['id'];
                    $this->helper->debug('📥 SettingsHandler: Mapping campaign', [
                        'legacy_key' => $legacy_key,
                        'env_key' => $env_key,
                        'campaign_id' => $data['id'],
                        'campaign_name' => $data['name']
                    ]);
                }
            }
            
            // Save environment-specific settings
            if (!empty($settings_to_update)) {
                $update_result = $settingsManager->update($settings_to_update);
                
                if ($update_result) {
                    $funds_count = count($results['funds']);
                    $campaigns_count = count($results['campaigns']);
                    $env_label = strtoupper($current_env);
                    $message = sprintf(
                        '%s environment: Fund and Campaign IDs synced successfully! Found %d fund(s) and %d campaign(s).',
                        $env_label,
                        $funds_count,
                        $campaigns_count
                    );
                    $this->redirectWithMessage('updated', $message, 'memberships');
                } else {
                    $this->redirectWithMessage('error', 'Failed to save synced IDs to settings.', 'memberships');
                }
            } else {
                $this->redirectWithMessage('error', 'No valid IDs found to sync.', 'memberships');
            }
        } else {
            $error_message = 'Failed to sync IDs. ';
            if (!empty($results['errors'])) {
                $error_message .= implode(' ', $results['errors']);
            } else {
                $error_message .= 'Check API connection.';
            }
            $this->redirectWithMessage('error', $error_message, 'memberships');
        }
    }
    
    /**
     * Handle sync groups from LGL API
     * Uses the current environment's API connection
     */
    public function handleSyncGroups(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'lgl_sync_groups')) {
            wp_die('Security check failed');
        }
        
        $settingsManager = $this->getSettingsManager();
        
        if (!$settingsManager) {
            $this->redirectWithMessage('error', 'Settings manager not available. Please try again.', 'memberships');
            return;
        }
        
        // Get current environment - groups will be fetched using this environment's API credentials
        $current_env = $settingsManager->getEnvironment();
        $current_env_label = ucfirst($current_env);
        
        // Sync groups - this will use the current environment's API connection
        $results = $settingsManager->syncGroups();
        
        if ($results['success']) {
            $groups_count = count($results['groups']);
            $message = sprintf(
                '%s environment: Groups synced successfully! Found %d group(s).',
                $current_env_label,
                $groups_count
            );
            
            $this->helper->info('LGL SettingsHandler: Groups synced successfully', [
                'environment' => $current_env,
                'groups_count' => $groups_count
            ]);
            
            $this->redirectWithMessage('updated', $message, 'memberships');
        } else {
            $error_message = sprintf('%s environment: Failed to sync groups. ', $current_env_label);
            if (!empty($results['errors'])) {
                $error_message .= implode(' ', $results['errors']);
            } else {
                $error_message .= 'Check API connection.';
            }
            
            $this->helper->error('LGL SettingsHandler: Groups sync failed', [
                'environment' => $current_env,
                'errors' => $results['errors'] ?? []
            ]);
            
            $this->redirectWithMessage('error', $error_message, 'memberships');
        }
    }
    
    /**
     * Handle role mappings settings save
     */
    public function handleRoleMappings(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lgl_role_mappings_settings')) {
            wp_die('Security check failed');
        }
        
        $settingsManager = $this->getSettingsManager();
        
        if (!$settingsManager) {
            $this->redirectWithMessage('error', 'Settings manager not available. Please try again.');
            return;
        }
        
        // Process role_group_mappings
        $role_group_mappings = [];
        if (isset($_POST['role_group_mappings']) && is_array($_POST['role_group_mappings'])) {
            foreach ($_POST['role_group_mappings'] as $role => $config) {
                if (isset($config['lgl_group_id']) && is_numeric($config['lgl_group_id'])) {
                    $role_group_mappings[$role] = [
                        'wp_role' => $role,
                        'lgl_group_id' => (int)$config['lgl_group_id'],
                        'lgl_group_key' => $this->getGroupKeyFromId((int)$config['lgl_group_id'])
                    ];
                }
            }
        }
        
        // Process coupon_role_mappings (legacy fallback - kept for backward compatibility)
        // Note: Preferred method is now via WooCommerce coupon meta fields (CouponRoleMeta)
        // This fallback is only used if coupon doesn't have role assignment in meta
        $coupon_role_mappings = [];
        if (isset($_POST['coupon_role_mappings']) && is_array($_POST['coupon_role_mappings'])) {
            foreach ($_POST['coupon_role_mappings'] as $coupon => $role) {
                $coupon_upper = strtoupper(trim($coupon));
                $role_sanitized = sanitize_text_field($role);
                if (!empty($coupon_upper) && !empty($role_sanitized)) {
                    $coupon_role_mappings[$coupon_upper] = $role_sanitized;
                }
            }
        }
        
        // Update settings
        $role_settings = [
            'role_group_mappings' => $role_group_mappings,
            'coupon_role_mappings' => $coupon_role_mappings
        ];
        
        if ($this->updateSettings($role_settings)) {
            $this->redirectWithMessage('updated', 'Role mappings saved successfully!');
        } else {
            $this->redirectWithMessage('error', 'Failed to save role mappings.');
        }
    }
    
    /**
     * Get group key from group ID (helper for mapping)
     * 
     * @param int $group_id LGL group ID
     * @return string Group key
     */
    private function getGroupKeyFromId(int $group_id): string {
        // Map known group IDs to keys
        $group_map = [
            3287 => 'teacher',
            3267 => 'board_member',
            3272 => 'staff',
            3277 => 'volunteer',
            3282 => 'partner',
            3262 => 'team_member',
            3291 => 'iwg_committee',
            3296 => 'language_student'
        ];
        
        return $group_map[$group_id] ?? 'unknown';
    }
    
    /**
     * Handle connection test AJAX request
     */
    public function handleConnectionTest(): void {
        // Check permissions - be more lenient for AJAX requests
        if (!current_user_can('manage_options') && !current_user_can('edit_posts')) {
            $this->helper->error('LGL SettingsHandler: User lacks sufficient permissions for connection test');
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lgl_admin_nonce')) {
            $this->helper->error('LGL SettingsHandler: Nonce verification failed');
            wp_send_json_error('Nonce verification failed');
            return;
        }
        
        // Get API credentials from form or use saved settings (sanitized)
        $api_url = \UpstateInternational\LGL\Core\Utilities::getSanitizedPost('api_url', 'url', null);
        $api_key = \UpstateInternational\LGL\Core\Utilities::getSanitizedPost('api_key', 'text', null);
        
        // Try to delegate to SettingsManager
        $manager = $this->getSettingsManager();
        if ($manager) {
            $result = $manager->testConnection($api_url, $api_key);
        } else {
            // Fallback: basic connection test
            if (empty($api_url) || empty($api_key)) {
                $settings = $this->getSettings();
                $api_url = $api_url ?: $settings['api_url'];
                $api_key = $api_key ?: $settings['api_key'];
            }
            
            if (empty($api_url) || empty($api_key)) {
                wp_send_json_error(['message' => 'API URL and API Key are required']);
                return;
            }
            
            $result = $this->testApiConnection($api_url, $api_key);
        }
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
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
        
        foreach ($endpoints_to_test as $endpoint) {
            $test_url = $base_url . $endpoint;
            
            $response = wp_remote_get($test_url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'timeout' => 10
            ]);
            
            if (is_wp_error($response)) {
                continue; // Try next endpoint
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($status_code === 200) {
                $data = json_decode($body, true);
                $this->helper->info('LGL SettingsHandler: API connection test successful', [
                    'endpoint' => $test_url
                ]);
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
                $this->helper->error('LGL SettingsHandler: API authentication failed', [
                    'endpoint' => $test_url,
                    'status_code' => $status_code
                ]);
                return [
                    'success' => false,
                    'message' => "Authentication failed (HTTP 401). Please check your API key.",
                    'status_code' => $status_code,
                    'endpoint_tested' => $test_url
                ];
            } elseif ($status_code === 403) {
                // Forbidden - API key might not have permission
                $this->helper->error('LGL SettingsHandler: API access forbidden', [
                    'endpoint' => $test_url,
                    'status_code' => $status_code
                ]);
                return [
                    'success' => false,
                    'message' => "Access forbidden (HTTP 403). Your API key might not have sufficient permissions.",
                    'status_code' => $status_code,
                    'endpoint_tested' => $test_url
                ];
            }
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
        // IMPORTANT: Preserve all keys since SettingsManager handles comprehensive sanitization
        // This callback is only for WordPress's register_setting compatibility
        // Just return the input as-is since SettingsManager::sanitizeSettings() already handles it
        return $input;
    }
    
    /**
     * Redirect with admin message
     */
    private function redirectWithMessage(string $type, string $message, string $tab = ''): void {
        $args = [
            'page' => 'lgl-settings', // Use the correct page slug
            'message' => urlencode($message),
            'type' => $type
        ];
        
        if (!empty($tab)) {
            $args['tab'] = $tab;
        }
        
        $redirect_url = add_query_arg($args, admin_url('admin.php'));
        
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

    private function importMembershipLevelsFromSchema(string $schema): array {
        $data = $this->loadSchemaData($schema, 'lgl-membership_levels.json');
        if (empty($data['items'])) {
            return [];
        }
        
        $levels = [];
        foreach ($data['items'] as $item) {
            if (!isset($item['id']) || !isset($item['name'])) {
                continue;
            }
            $levels[] = [
                'level_name' => $item['name'],
                'level_slug' => \sanitize_title($item['name']),
                'lgl_membership_level_id' => (int) $item['id'],
                'price' => isset($item['price']) ? (float) $item['price'] : 0
            ];
        }
        return $levels;
    }
    
    private function importFundMappingsFromSchema(string $schema): array {
        $data = $this->loadSchemaData($schema, 'lgl-funds.json');
        if (empty($data['items'])) {
            return [];
        }
        $funds = [];
        foreach ($data['items'] as $item) {
            if (!isset($item['id']) || !isset($item['name'])) {
                continue;
            }
            $funds[$item['name']] = (int) $item['id'];
        }
        return $funds;
    }
    
    private function importCampaignMappingsFromSchema(string $schema): array {
        $data = $this->loadSchemaData($schema, 'lgl-campaigns.json');
        if (empty($data['items'])) {
            return [];
        }
        $campaigns = [];
        foreach ($data['items'] as $item) {
            if (!isset($item['id']) || !isset($item['name'])) {
                continue;
            }
            $campaigns[$item['name']] = (int) $item['id'];
        }
        return $campaigns;
    }
    
    private function importPaymentTypesFromSchema(string $schema): array {
        $data = $this->loadSchemaData($schema, 'lgl-payment_types.json');
        if (empty($data['items'])) {
            return [];
        }
        $types = [];
        foreach ($data['items'] as $item) {
            if (!isset($item['id']) || !isset($item['name'])) {
                continue;
            }
            $types[$item['name']] = (int) $item['id'];
        }
        return $types;
    }
    
    private function importRelationEndpointsFromSchema(string $schema): array {
        $data = $this->loadSchemaData($schema, 'lgl-group_memberships.json');
        return is_array($data) ? $data : [];
    }
    
    private function loadSchemaData(string $schema, string $fallbackFile): array {
        $baseDir = \trailingslashit(LGL_PLUGIN_DIR) . 'docs/lgl-exports/';
        $file = $schema && file_exists($baseDir . $schema) ? $baseDir . $schema : $baseDir . $fallbackFile;
        if (!file_exists($file)) {
            return [];
        }
        $json = file_get_contents($file);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
    
    /**
     * Handle AJAX request to import membership levels from LGL API
     */
    public function handleImportMembershipLevels(): void {
        // Verify nonce
        check_ajax_referer('lgl_import_levels', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Insufficient permissions to import membership levels.'
            ]);
            return;
        }
        
        // Get SettingsManager instance
        $settingsManager = $this->getSettingsManager();
        
        if (!$settingsManager) {
            $this->helper->error('LGL SettingsHandler: SettingsManager not available for import');
            wp_send_json_error([
                'message' => 'Settings manager not available. Please try again.'
            ]);
            return;
        }
        
        // Import membership levels via SettingsManager
        $result = $settingsManager->importMembershipLevels();
        
        if ($result['success']) {
            $this->helper->info('LGL SettingsHandler: Membership levels imported', [
                'count' => $result['count'] ?? 0
            ]);
            wp_send_json_success($result);
        } else {
            $this->helper->error('LGL SettingsHandler: Membership levels import failed', [
                'errors' => $result['errors'] ?? []
            ]);
            wp_send_json_error($result);
        }
    }
    
    /**
     * Handle AJAX request to import events from LGL API
     * 
     * @return void Outputs JSON response
     */
    public function handleImportEvents(): void {
        // Verify nonce
        check_ajax_referer('lgl_import_events', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Insufficient permissions to import events.'
            ]);
            return;
        }
        
        // Get SettingsManager instance
        $settingsManager = $this->getSettingsManager();
        
        if (!$settingsManager) {
            $this->helper->error('LGL SettingsHandler: SettingsManager not available for events import');
            wp_send_json_error([
                'message' => 'Settings manager not available. Please try again.'
            ]);
            return;
        }
        
        // Import events via SettingsManager
        $result = $settingsManager->importEvents();
        
        if ($result['success']) {
            $this->helper->info('LGL SettingsHandler: Events imported', [
                'count' => $result['count'] ?? 0
            ]);
            wp_send_json_success($result);
        } else {
            $this->helper->error('LGL SettingsHandler: Events import failed', [
                'errors' => $result['errors'] ?? []
            ]);
            wp_send_json_error($result);
        }
    }
    
    /**
     * Handle AJAX request to import funds from LGL API
     * 
     * @return void Outputs JSON response
     */
    public function handleImportFunds(): void {
        // Verify nonce
        check_ajax_referer('lgl_import_funds', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Insufficient permissions to import funds.'
            ]);
            return;
        }
        
        // Get SettingsManager instance
        $settingsManager = $this->getSettingsManager();
        
        if (!$settingsManager) {
            $this->helper->error('LGL SettingsHandler: SettingsManager not available for funds import');
            wp_send_json_error([
                'message' => 'Settings manager not available. Please try again.'
            ]);
            return;
        }
        
        // Import funds via SettingsManager
        $result = $settingsManager->importFunds();
        
        if ($result['success']) {
            $this->helper->info('LGL SettingsHandler: Funds imported', [
                'count' => $result['count'] ?? 0
            ]);
            wp_send_json_success($result);
        } else {
            $this->helper->error('LGL SettingsHandler: Funds import failed', [
                'errors' => $result['errors'] ?? []
            ]);
            wp_send_json_error($result);
        }
    }
}
?>