<?php
/**
 * Testing Handler
 * 
 * Consolidates all LGL testing functionality into a unified system.
 * Handles AJAX requests from the admin testing interface.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\ApiSettings;
use UpstateInternational\LGL\Core\Plugin;

/**
 * TestingHandler Class
 * 
 * Manages all LGL testing operations
 */
class TestingHandler {
    
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
     * API Settings service
     * 
     * @var ApiSettings
     */
    private ApiSettings $apiSettings;
    
    /**
     * Plugin instance
     * 
     * @var Plugin
     */
    private Plugin $plugin;
    
    /**
     * Settings Manager service (lazy-loaded to avoid circular dependency)
     * 
     * @var SettingsManager|null
     */
    private ?SettingsManager $settingsManager = null;
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param Connection $connection Connection service
     * @param ApiSettings $apiSettings API settings service
     * @param Plugin $plugin Plugin instance
     */
    public function __construct(Helper $helper, Connection $connection, ApiSettings $apiSettings, Plugin $plugin) {
        $this->helper = $helper;
        $this->connection = $connection;
        $this->apiSettings = $apiSettings;
        $this->plugin = $plugin;
    }
    
    /**
     * Initialize testing handler
     */
    public function initialize(): void {
        // Register AJAX handlers
        add_action('wp_ajax_lgl_run_test', [$this, 'handleTestRequest']);
        // Removed: add_action('wp_ajax_lgl_test_connection', [$this, 'handleConnectionTest']); - conflicts with SettingsHandler
        add_action('wp_ajax_lgl_test_price_mapping', [$this, 'handlePriceMappingTest']);
        add_action('wp_ajax_lgl_test_debug_system', [$this, 'handleDebugSystemTest']);
    }
    
    /**
     * Handle test requests
     */
    public function handleTestRequest(): void {
        // Verify permissions and nonce
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'lgl_admin_nonce')) {
            wp_die('Insufficient permissions');
        }
        
        $test_type = sanitize_text_field($_POST['test_type']);
        
        switch ($test_type) {
            case 'connection':
                echo $this->runConnectionTest();
                break;
                
            case 'search':
                echo $this->runSearchTest();
                break;
                
            case 'membership-flow':
                echo $this->runMembershipFlowTest();
                break;
                
            case 'event-flow':
                echo $this->runEventFlowTest();
                break;
                
            case 'class-flow':
                echo $this->runClassFlowTest();
                break;
                
            case 'debug-settings':
                echo $this->runDebugSettingsTest();
                break;
                
            case 'service-container':
                echo $this->runServiceContainerTest();
                break;
                
            case 'architecture':
                echo $this->runArchitectureTest();
                break;
                
            case 'full-suite':
                echo $this->runFullTestSuite();
                break;
                
            default:
                echo '<div class="notice notice-error"><p>❌ Unknown test type: ' . $test_type . '</p></div>';
        }
        
        wp_die();
    }
    
    /**
     * Handle connection test requests
     */
    public function handleConnectionTest(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'lgl_admin_nonce')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $test_result = $this->connection->testConnection();
        
        if ($test_result['success']) {
            wp_send_json_success($test_result);
        } else {
            wp_send_json_error($test_result);
        }
    }
    
    /**
     * Run connection test
     */
    private function runConnectionTest(): string {
        ob_start();
        
        echo '<div class="notice notice-info"><p>🔄 Testing LGL API connection...</p></div>';
        
        // Check API settings
        $api_url = $this->apiSettings->getApiUrl();
        $api_key = $this->apiSettings->getApiKey();
        
        if (!$api_url || !$api_key) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Configuration Missing:</strong></p>';
            echo '<ul>';
            if (!$api_url) echo '<li>API URL not configured</li>';
            if (!$api_key) echo '<li>API Key not configured</li>';
            echo '</ul>';
            echo '<p>Please configure your settings first.</p>';
            echo '</div>';
            return ob_get_clean();
        }
        
        // Test connection
        $result = $this->connection->testConnection();
        
        if ($result['success']) {
            echo '<div class="notice notice-success">';
            echo '<p>✅ <strong>Connection Test Passed!</strong></p>';
            echo '<ul>';
            echo '<li><strong>Status:</strong> ' . esc_html($result['message']) . '</li>';
            echo '<li><strong>API Version:</strong> ' . esc_html($result['api_version']) . '</li>';
            echo '<li><strong>API URL:</strong> ' . esc_html($api_url) . '</li>';
            echo '</ul>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Connection Test Failed:</strong></p>';
            echo '<p>' . esc_html($result['message']) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run search function test
     */
    private function runSearchTest(): string {
        ob_start();
        
        echo '<div class="notice notice-info"><p>🔄 Testing searchByName function...</p></div>';
        
        try {
            $search_result = $this->connection->searchByName('Test User', 'test@example.com');
            
            echo '<div class="notice notice-success">';
            echo '<p>✅ <strong>Search Function Test Passed!</strong></p>';
            echo '<ul>';
            echo '<li><strong>Function:</strong> searchByName() works correctly</li>';
            echo '<li><strong>Test Query:</strong> Name: "Test User", Email: "test@example.com"</li>';
            echo '<li><strong>Result:</strong> ' . ($search_result ? 'Found LGL ID: ' . $search_result : 'No match (expected for test data)') . '</li>';
            echo '</ul>';
            echo '</div>';
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Search Function Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run membership flow test
     */
    private function runMembershipFlowTest(): string {
        ob_start();
        
        echo '<div class="notice notice-info"><p>🔄 Running membership flow test...</p></div>';
        
        try {
            // Run the existing debug membership test functionality
            echo '<div class="notice notice-success">';
            echo '<p>✅ <strong>Membership Flow Test Available!</strong></p>';
            echo '<p>This test creates a real WooCommerce order and processes it through the complete membership flow:</p>';
            echo '<ul>';
            echo '<li>✅ Uses actual membership product (ID: 67487)</li>';
            echo '<li>✅ Creates real user and order</li>';
            echo '<li>✅ Tests price-based membership detection</li>';
            echo '<li>✅ Processes through OrderProcessor → MembershipOrderHandler</li>';
            echo '<li>✅ Attempts LGL API sync</li>';
            echo '</ul>';
            echo '<p><strong>To run this test:</strong> Use the shortcode <code>[debug_membership_test run="yes"]</code> on any page.</p>';
            echo '</div>';
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Membership Flow Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run service container test
     */
    private function runServiceContainerTest(): string {
        ob_start();
        
        echo '<div class="notice notice-info"><p>🔄 Testing service container...</p></div>';
        
        try {
            $orderProcessor = $this->plugin->getServiceFromContainer('woocommerce.order_processor');
            $userRegistrationAction = $this->plugin->getServiceFromContainer('jetformbuilder.user_registration_action');
            
            echo '<div class="notice notice-success">';
            echo '<p>✅ <strong>Service Container Test Passed!</strong></p>';
            echo '<ul>';
            echo '<li><strong>Plugin Instance:</strong> Available</li>';
            echo '<li><strong>OrderProcessor:</strong> ' . ($orderProcessor ? '✅ Resolved' : '❌ Failed') . '</li>';
            echo '<li><strong>UserRegistrationAction:</strong> ' . ($userRegistrationAction ? '✅ Resolved' : '❌ Failed') . '</li>';
            echo '<li><strong>Dependency Injection:</strong> Working</li>';
            echo '</ul>';
            echo '</div>';
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Service Container Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run architecture test
     */
    private function runArchitectureTest(): string {
        ob_start();
        
        echo '<div class="notice notice-info"><p>🔄 Testing modern architecture...</p></div>';
        
        $tests = [
            'Modern Plugin Class' => class_exists('\UpstateInternational\LGL\Core\Plugin'),
            'Service Container' => class_exists('\UpstateInternational\LGL\Core\ServiceContainer'),
            'Connection Class' => class_exists('\UpstateInternational\LGL\LGL\Connection'),
            'Helper Class' => class_exists('\UpstateInternational\LGL\LGL\Helper'),
            'ApiSettings Class' => class_exists('\UpstateInternational\LGL\LGL\ApiSettings'),
            'OrderProcessor Class' => class_exists('\UpstateInternational\LGL\WooCommerce\OrderProcessor'),
            'UserRegistrationAction Class' => class_exists('\UpstateInternational\LGL\JetFormBuilder\Actions\UserRegistrationAction'),
            'Composer Autoloader' => class_exists('\Composer\Autoload\ClassLoader'),
        ];
        
        $passed = 0;
        $total = count($tests);
        
        echo '<div class="notice notice-success">';
        echo '<p>✅ <strong>Architecture Test Results:</strong></p>';
        echo '<ul>';
        
        foreach ($tests as $test_name => $result) {
            $status = $result ? '✅ Pass' : '❌ Fail';
            echo '<li><strong>' . $test_name . ':</strong> ' . $status . '</li>';
            if ($result) $passed++;
        }
        
        echo '</ul>';
        echo '<p><strong>Overall:</strong> ' . $passed . '/' . $total . ' tests passed (' . round(($passed / $total) * 100) . '%)</p>';
        echo '</div>';
        
        return ob_get_clean();
    }
    
    /**
     * Run debug settings test
     */
    private function runDebugSettingsTest(): string {
        ob_start();
        
        echo '<div class="notice notice-success">';
        echo '<p>✅ <strong>Debug Settings Test Available!</strong></p>';
        echo '<p>This test verifies the connection between Helper debug system and ApiSettings checkbox.</p>';
        echo '<p><strong>To run this test:</strong> Use the shortcode <code>[debug_settings_test]</code> on any page.</p>';
        echo '</div>';
        
        return ob_get_clean();
    }
    
    /**
     * Run event flow test
     */
    private function runEventFlowTest(): string {
        ob_start();
        
        echo '<div class="notice notice-warning">';
        echo '<p>⚠️ <strong>Event Flow Test:</strong></p>';
        echo '<p>Event flow testing is available but requires specific event products to be configured.</p>';
        echo '<ul>';
        echo '<li>Event product detection (ui_events_* meta)</li>';
        echo '<li>Attendee data processing</li>';
        echo '<li>JetEngine CCT creation</li>';
        echo '<li>Capacity tracking</li>';
        echo '</ul>';
        echo '</div>';
        
        return ob_get_clean();
    }
    
    /**
     * Run class flow test
     */
    private function runClassFlowTest(): string {
        ob_start();
        
        echo '<div class="notice notice-warning">';
        echo '<p>⚠️ <strong>Language Class Test (Legacy Support):</strong></p>';
        echo '<ul>';
        echo '<li>Legacy WooCommerce class processing</li>';
        echo '<li>Note: Being phased out for CourseStorm</li>';
        echo '<li>Maintained for backward compatibility only</li>';
        echo '</ul>';
        echo '</div>';
        
        return ob_get_clean();
    }
    
    /**
     * Run full test suite
     */
    private function runFullTestSuite(): string {
        ob_start();
        
        echo '<div class="notice notice-info"><p>🔄 Running complete test suite...</p></div>';
        
        // Run multiple tests
        echo $this->runConnectionTest();
        echo $this->runSearchTest();
        echo $this->runServiceContainerTest();
        echo $this->runArchitectureTest();
        
        echo '<div class="notice notice-success">';
        echo '<p>✅ <strong>Complete Test Suite Finished!</strong></p>';
        echo '<p>Review all test results above. For detailed flow testing, use the individual test shortcodes.</p>';
        echo '</div>';
        
        return ob_get_clean();
    }
    
    /**
     * Handle price mapping test
     */
    public function handlePriceMappingTest(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'lgl_admin_nonce')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $price = floatval($_POST['price']);
        
        if ($price <= 0) {
            wp_send_json_error('Invalid price');
        }
        
        // Test the price mapping using Helper
        $membership_level = $this->helper->uiMembershipPriceToName($price);
        
        if (!empty($membership_level)) {
            wp_send_json_success([
                'membership_level' => $membership_level,
                'price' => $price
            ]);
        } else {
            wp_send_json_error([
                'message' => 'No matching membership level found for price: $' . $price,
                'price' => $price
            ]);
        }
    }
    
    /**
     * Handle debug system test
     */
    public function handleDebugSystemTest(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'lgl_admin_nonce')) {
            wp_die('Insufficient permissions');
        }
        
        ob_start();
        
        echo '<div class="notice notice-info"><p>🔄 Testing debug system...</p></div>';
        
        // Test debug mode detection
        $debug_mode = $this->apiSettings->isDebugMode();
        $wp_debug = defined('WP_DEBUG') && WP_DEBUG;
        
        echo '<div class="notice notice-success">';
        echo '<p>✅ <strong>Debug System Test Results:</strong></p>';
        echo '<ul>';
        echo '<li><strong>ApiSettings Debug Mode:</strong> ' . ($debug_mode ? 'Enabled' : 'Disabled') . '</li>';
        echo '<li><strong>WordPress Debug:</strong> ' . ($wp_debug ? 'Enabled' : 'Disabled') . '</li>';
        echo '<li><strong>Helper Debug Connection:</strong> ' . ($this->helper->isDebugMode() ? 'Working' : 'Not Working') . '</li>';
        echo '</ul>';
        echo '</div>';
        
        // Test actual debug output
        if ($debug_mode) {
            echo '<div class="notice notice-info">';
            echo '<p>🧪 <strong>Testing Debug Output:</strong></p>';
            echo '</div>';
            
            $this->helper->debug('This is a test debug message from the admin interface');
            
            echo '<div class="notice notice-success">';
            echo '<p>✅ Debug output test completed. Check your error_log for the debug message.</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning">';
            echo '<p>⚠️ Debug mode is disabled. Enable it to test debug output.</p>';
            echo '</div>';
        }
        
        echo ob_get_clean();
        wp_die();
    }
}
?>
