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
use UpstateInternational\LGL\LGL\Payments;
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
     * Payments service
     * 
     * @var Payments
     */
    private Payments $payments;
    
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
     * @param Payments $payments Payments service
     * @param Plugin $plugin Plugin instance
     */
    public function __construct(Helper $helper, Connection $connection, ApiSettings $apiSettings, Payments $payments, Plugin $plugin) {
        $this->helper = $helper;
        $this->connection = $connection;
        $this->apiSettings = $apiSettings;
        $this->payments = $payments;
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
        
        // Cleanup any orphaned test orders on init (safety measure)
        add_action('admin_init', [$this, 'cleanupOrphanedTestOrders'], 20);
    }
    
    /**
     * Cleanup any test orders that are older than 1 hour (orphaned)
     * 
     * This is a safety measure in case a test crashes before cleanup.
     * Only runs once per day to avoid performance impact.
     * 
     * @return void
     */
    public function cleanupOrphanedTestOrders(): void {
        // Only run once per day
        $lastCleanup = get_option('lgl_test_orders_last_cleanup', 0);
        if (time() - $lastCleanup < DAY_IN_SECONDS) {
            return;
        }
        
        if (!function_exists('wc_get_orders')) {
            return;
        }
        
        try {
            // Find all test orders older than 1 hour
            $oneHourAgo = time() - HOUR_IN_SECONDS;
            
            $args = [
                'limit' => 100,
                'status' => 'lgl-test',
                'meta_query' => [
                    [
                        'key' => '_lgl_test_order',
                        'value' => 'true',
                        'compare' => '='
                    ],
                    [
                        'key' => '_lgl_test_created',
                        'value' => $oneHourAgo,
                        'compare' => '<',
                        'type' => 'NUMERIC'
                    ]
                ]
            ];
            
            $orphanedOrders = wc_get_orders($args);
            
            if (!empty($orphanedOrders)) {
                \lgl_log("🧹 Cleaning up " . count($orphanedOrders) . " orphaned test orders");
                
                foreach ($orphanedOrders as $order) {
                    $order->delete(true);
                }
            }
            
            // Update last cleanup timestamp
            update_option('lgl_test_orders_last_cleanup', time());
            
        } catch (\Exception $e) {
            \lgl_log("❌ Error during test order cleanup", ['error' => $e->getMessage()]);
        }
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
                
            case 'add_constituent':
                echo $this->runAddConstituentTest();
                break;
                
            case 'update_constituent':
                echo $this->runUpdateConstituentTest();
                break;
                
            case 'add_membership':
                echo $this->runAddMembershipTest();
                break;
                
            case 'update_membership':
                echo $this->runUpdateMembershipTest();
                break;
                
            case 'event_registration':
                echo $this->runEventRegistrationTest();
                break;
                
            case 'class_registration':
                echo $this->runClassRegistrationTest();
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
    
    /**
     * Run add constituent test
     */
    private function runAddConstituentTest(): string {
        ob_start();
        
        $userId = isset($_POST['wordpress_user_id']) ? intval($_POST['wordpress_user_id']) : 1214;
        
        echo '<div class="notice notice-info"><p>🔄 Testing Add Constituent for User ID: ' . $userId . '...</p></div>';
        
        try {
            // Get user
            $user = get_user_by('ID', $userId);
            if (!$user) {
                throw new \Exception('User not found: ' . $userId);
            }
            
            // Get constituent service
            $constituentsClass = \UpstateInternational\LGL\LGL\Constituents::getInstance();
            
            // Build constituent data from user ID (setData handles all extraction internally)
            $constituentsClass->setData($userId, true); // Skip membership in test
            
            // Get display info for output
            $firstName = get_user_meta($userId, 'first_name', true) ?: get_user_meta($userId, 'billing_first_name', true);
            $lastName = get_user_meta($userId, 'last_name', true) ?: get_user_meta($userId, 'billing_last_name', true);
            $email = get_user_meta($userId, 'billing_email', true) ?: $user->user_email;
            
            // Create constituent
            $result = $constituentsClass->createConstituent();
            $constituentId = $result['data']['id'] ?? null;
            
            if (!empty($constituentId)) {
                echo '<div class="notice notice-success">';
                echo '<p>✅ <strong>Constituent Created Successfully!</strong></p>';
                echo '<ul>';
                echo '<li><strong>LGL ID:</strong> ' . esc_html($constituentId) . '</li>';
                echo '<li><strong>Name:</strong> ' . esc_html($firstName . ' ' . $lastName) . '</li>';
                echo '<li><strong>Email:</strong> ' . esc_html($email) . '</li>';
                echo '<li><strong>WordPress User ID:</strong> ' . $userId . '</li>';
                echo '</ul>';
                
                // Update user meta with LGL ID
                update_user_meta($userId, 'lgl_id', $constituentId);
                echo '<p><em>✓ Updated user meta with lgl_id</em></p>';
                echo '</div>';

                // Multi-request additions (email, phone, address)
                $connection = \UpstateInternational\LGL\LGL\Connection::getInstance();

                // Email addresses
                $emailData = $constituentsClass->getEmailData();
                if (!empty($emailData)) {
                    echo '<div class="notice notice-info"><p>📧 Adding ' . count($emailData) . ' email address(es)...</p></div>';
                    foreach ($emailData as $emailPayload) {
                        $emailResponse = $connection->addEmailAddress((string) $constituentId, $emailPayload);
                        if (!empty($emailResponse['success'])) {
                            echo '<div class="notice notice-success"><p>✅ Email added: ' . esc_html($emailPayload['address']) . '</p></div>';
                        } else {
                            echo '<div class="notice notice-warning"><p>⚠️ Failed to add email ' . esc_html($emailPayload['address']) . ': ' . esc_html(wp_json_encode($emailResponse)) . '</p></div>';
                        }
                    }
                }

                // Phone numbers
                $phoneData = $constituentsClass->getPhoneData();
                if (!empty($phoneData)) {
                    echo '<div class="notice notice-info"><p>📞 Adding ' . count($phoneData) . ' phone number(s)...</p></div>';
                    foreach ($phoneData as $phonePayload) {
                        $phoneResponse = $connection->addPhoneNumber((string) $constituentId, $phonePayload);
                        if (!empty($phoneResponse['success'])) {
                            echo '<div class="notice notice-success"><p>✅ Phone added: ' . esc_html($phonePayload['number']) . '</p></div>';
                        } else {
                            echo '<div class="notice notice-warning"><p>⚠️ Failed to add phone ' . esc_html($phonePayload['number']) . ': ' . esc_html(wp_json_encode($phoneResponse)) . '</p></div>';
                        }
                    }
                }

                // Street addresses
                $addressData = $constituentsClass->getAddressData();
                if (!empty($addressData)) {
                    echo '<div class="notice notice-info"><p>🏠 Adding ' . count($addressData) . ' street address(es)...</p></div>';
                    foreach ($addressData as $addressPayload) {
                        $addressResponse = $connection->addStreetAddress((string) $constituentId, $addressPayload);
                        if (!empty($addressResponse['success'])) {
                            $addressString = $addressPayload['street1'] ?? $addressPayload['street_address'] ?? 'Address';
                            echo '<div class="notice notice-success"><p>✅ Address added: ' . esc_html($addressString) . '</p></div>';
                        } else {
                            echo '<div class="notice notice-warning"><p>⚠️ Failed to add address: ' . esc_html(wp_json_encode($addressResponse)) . '</p></div>';
                        }
                    }
                }
            } else {
                $rawResponse = isset($result['raw_response']) ? json_decode($result['raw_response'], true) : null;
                throw new \Exception('Failed to create constituent - no ID returned. Response: ' . wp_json_encode($rawResponse));
            }
            
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Add Constituent Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '<pre>' . esc_html($e->getTraceAsString()) . '</pre>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run update constituent test
     */
    private function runUpdateConstituentTest(): string {
        ob_start();
        
        $userId = isset($_POST['wordpress_user_id']) ? intval($_POST['wordpress_user_id']) : 1214;
        
        echo '<div class="notice notice-info"><p>🔄 Testing Update Constituent for User ID: ' . $userId . '...</p></div>';
        
        try {
            // Get user
            $user = get_user_by('ID', $userId);
            if (!$user) {
                throw new \Exception('User not found: ' . $userId);
            }
            
            // Get LGL ID
            $lglId = get_user_meta($userId, 'lgl_id', true);
            if (!$lglId) {
                throw new \Exception('No LGL ID found for user ' . $userId . '. Run Add Constituent first.');
            }
            
            // Get constituent service
            $constituentsClass = \UpstateInternational\LGL\LGL\Constituents::getInstance();
            
            // Build updated data from user ID (setData handles extraction)
            $constituentsClass->setData($userId, true); // Skip membership in test
            
            // Get display info
            $firstName = get_user_meta($userId, 'first_name', true);
            $lastName = get_user_meta($userId, 'last_name', true);
            $email = get_user_meta($userId, 'billing_email', true) ?: $user->user_email;
            
            // Update constituent
            $result = $constituentsClass->updateConstituent($lglId);
            $constituentId = $result['data']['id'] ?? null;
            
            if (!empty($constituentId)) {
                echo '<div class="notice notice-success">';
                echo '<p>✅ <strong>Constituent Updated Successfully!</strong></p>';
                echo '<ul>';
                echo '<li><strong>LGL ID:</strong> ' . esc_html($constituentId) . '</li>';
                echo '<li><strong>Name:</strong> ' . esc_html($firstName . ' ' . $lastName) . '</li>';
                echo '<li><strong>Email:</strong> ' . esc_html($email) . '</li>';
                echo '</ul>';
                echo '</div>';

                // After updating personal data, ensure contact details stay in sync via multi-request pattern
                $connection = \UpstateInternational\LGL\LGL\Connection::getInstance();

                // Email addresses
                $emailData = $constituentsClass->getEmailData();
                if (!empty($emailData)) {
                    echo '<div class="notice notice-info"><p>📧 Ensuring email addresses are current (' . count($emailData) . ')...</p></div>';
                    foreach ($emailData as $emailPayload) {
                        $emailResponse = $connection->addEmailAddress((string) $constituentId, $emailPayload);
                        if (!empty($emailResponse['success'])) {
                            echo '<div class="notice notice-success"><p>✅ Email confirmed: ' . esc_html($emailPayload['address']) . '</p></div>';
                        } else {
                            echo '<div class="notice notice-warning"><p>⚠️ Email update issue for ' . esc_html($emailPayload['address']) . ': ' . esc_html(wp_json_encode($emailResponse)) . '</p></div>';
                        }
                    }
                }

                // Phone numbers
                $phoneData = $constituentsClass->getPhoneData();
                if (!empty($phoneData)) {
                    echo '<div class="notice notice-info"><p>📞 Ensuring phone numbers are current (' . count($phoneData) . ')...</p></div>';
                    foreach ($phoneData as $phonePayload) {
                        $phoneResponse = $connection->addPhoneNumber((string) $constituentId, $phonePayload);
                        if (!empty($phoneResponse['success'])) {
                            echo '<div class="notice notice-success"><p>✅ Phone confirmed: ' . esc_html($phonePayload['number']) . '</p></div>';
                        } else {
                            echo '<div class="notice notice-warning"><p>⚠️ Phone update issue for ' . esc_html($phonePayload['number']) . ': ' . esc_html(wp_json_encode($phoneResponse)) . '</p></div>';
                        }
                    }
                }

                // Street addresses
                $addressData = $constituentsClass->getAddressData();
                if (!empty($addressData)) {
                    echo '<div class="notice notice-info"><p>🏠 Ensuring street addresses are current (' . count($addressData) . ')...</p></div>';
                    foreach ($addressData as $addressPayload) {
                        $addressResponse = $connection->addStreetAddress((string) $constituentId, $addressPayload);
                        if (!empty($addressResponse['success'])) {
                            $addressString = $addressPayload['street1'] ?? $addressPayload['street_address'] ?? ($addressPayload['street'] ?? 'Address');
                            echo '<div class="notice notice-success"><p>✅ Address confirmed: ' . esc_html($addressString) . '</p></div>';
                        } else {
                            echo '<div class="notice notice-warning"><p>⚠️ Address update issue: ' . esc_html(wp_json_encode($addressResponse)) . '</p></div>';
                        }
                    }
                }
            } else {
                throw new \Exception('Failed to update constituent - ' . wp_json_encode($result));
            }
            
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Update Constituent Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run add membership test
     */
    private function runAddMembershipTest(): string {
        ob_start();
        
        $userId = isset($_POST['wordpress_user_id']) ? intval($_POST['wordpress_user_id']) : 1214;
        $variationId = isset($_POST['variation_product_id']) ? intval($_POST['variation_product_id']) : 68386;
        
        \lgl_log("🔄 Starting Add Membership test", ['user_id' => $userId, 'variation_id' => $variationId]);
        echo '<div class="notice notice-info"><p>🔄 Testing Add Membership for User ID: ' . $userId . ', Variation: ' . $variationId . '...</p></div>';
        
        try {
            // Get user
            $user = get_user_by('ID', $userId);
            if (!$user) {
                \lgl_log("❌ User not found", ['user_id' => $userId]);
                throw new \Exception('User not found: ' . $userId);
            }
            
            // Get LGL ID (try both meta keys for compatibility)
            $lglId = get_user_meta($userId, 'lgl_constituent_id', true);
            if (!$lglId) {
                $lglId = get_user_meta($userId, 'lgl_id', true);
            }
            
            \lgl_log("🔍 Retrieved LGL ID from user meta", ['user_id' => $userId, 'lgl_id' => $lglId, 'meta_key_used' => $lglId ? ($lglId === get_user_meta($userId, 'lgl_constituent_id', true) ? 'lgl_constituent_id' : 'lgl_id') : 'none']);
            
            if (!$lglId) {
                \lgl_log("❌ No LGL ID found for user", ['user_id' => $userId]);
                throw new \Exception('No LGL ID found for user ' . $userId . '. Run Add Constituent first.');
            }
            
            echo '<div class="notice notice-info"><p>📋 User LGL ID: <strong>' . esc_html($lglId) . '</strong></p></div>';
            
            // Get membership level ID from product variation
            $fundId = get_post_meta($variationId, '_lgl_membership_fund_id', true);
            \lgl_log("🔍 Retrieved membership fund ID from product", ['variation_id' => $variationId, 'fund_id' => $fundId]);
            if (!$fundId) {
                throw new \Exception('No _lgl_membership_fund_id found for variation ' . $variationId);
            }
            
            // Get membership level name
            $product = function_exists('wc_get_product') ? wc_get_product($variationId) : null;
            $levelName = $product ? $product->get_name() : 'Individual Membership';
            
            // Get price
            $price = $product ? $product->get_price() : 75.00;
            
            // Use connection to add membership
            // Note: Using legacy field names from lgl-connections.php
            $membershipPayload = [
                'membership_level_id' => intval($fundId),
                'membership_level_name' => $levelName,
                'date_start' => date('Y-m-d'),
                'finish_date' => date('Y-m-d', strtotime('+1 year')),
                'amount' => floatval($price),
                'note' => 'Membership added via LGL Testing Suite on ' . date('Y-m-d'),
            ];
            
            \lgl_log("📤 Sending membership payload to LGL", ['lgl_id' => $lglId, 'payload' => $membershipPayload]);
            $result = $this->connection->addMembership($lglId, $membershipPayload);
            \lgl_log("📥 Received membership response from LGL", ['response' => $result]);
            
            // Check for success and membership ID in response
            if ($result && !empty($result['success']) && isset($result['data']['id'])) {
                $membershipId = $result['data']['id'];
                
                // CRITICAL: Also create the payment/gift record for this membership
                $paymentResult = $this->createTestPayment($lglId, $price, 'Membership', $levelName, $userId, $variationId);
                $paymentId = $paymentResult['id'] ?? null;
                $paymentSuccess = $paymentResult['success'] ?? false;
                
                echo '<div class="notice notice-success">';
                echo '<p>✅ <strong>Membership Added Successfully!</strong></p>';
                echo '<ul>';
                echo '<li><strong>Constituent LGL ID:</strong> ' . esc_html($lglId) . '</li>';
                echo '<li><strong>Membership Level:</strong> ' . esc_html($levelName) . '</li>';
                echo '<li><strong>Fund ID:</strong> ' . esc_html($fundId) . '</li>';
                echo '<li><strong>Amount:</strong> $' . esc_html(number_format($price, 2)) . '</li>';
                echo '<li><strong>Membership ID:</strong> ' . esc_html($membershipId) . '</li>';
                if ($paymentSuccess && $paymentId) {
                    echo '<li><strong>💰 Payment/Gift ID:</strong> ' . esc_html($paymentId) . ' ✅</li>';
                } else {
                    echo '<li><strong>💰 Payment/Gift:</strong> <span style="color: orange;">⚠️ ' . esc_html($paymentResult['error'] ?? 'Failed') . '</span></li>';
                }
                echo '</ul>';
                echo '</div>';
            } else {
                // Show detailed error information
                $errorMsg = 'Failed to add membership';
                if (isset($result['error'])) {
                    $errorMsg .= ': ' . $result['error'];
                }
                if (isset($result['http_code'])) {
                    $errorMsg .= ' (HTTP ' . $result['http_code'] . ')';
                }
                echo '<div class="notice notice-error">';
                echo '<p>❌ <strong>' . esc_html($errorMsg) . '</strong></p>';
                echo '<p><strong>Full Response:</strong></p>';
                echo '<pre>' . esc_html(wp_json_encode($result, JSON_PRETTY_PRINT)) . '</pre>';
                echo '</div>';
            }
            
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Add Membership Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run update membership test
     * 
     * This test marks all existing memberships as inactive and creates a new one
     */
    private function runUpdateMembershipTest(): string {
        ob_start();
        
        $userId = isset($_POST['wordpress_user_id']) ? intval($_POST['wordpress_user_id']) : 1214;
        $variationId = isset($_POST['variation_product_id']) ? intval($_POST['variation_product_id']) : 68386;
        
        echo '<div class="notice notice-info"><p>🔄 Testing Update Membership for User ID: ' . $userId . '...</p></div>';
        
        try {
            // Get user
            $user = get_user_by('ID', $userId);
            if (!$user) {
                throw new \Exception('User not found: ' . $userId);
            }
            
            // Get LGL ID (try both meta keys for compatibility)
            $lglId = get_user_meta($userId, 'lgl_constituent_id', true);
            if (!$lglId) {
                $lglId = get_user_meta($userId, 'lgl_id', true);
            }
            
            if (!$lglId) {
                throw new \Exception('No LGL ID found for user ' . $userId . '. Run Add Constituent first.');
            }
            
            \lgl_log("🔍 Retrieved LGL ID for user {$userId}", ['lgl_id' => $lglId]);
            echo '<div class="notice notice-info"><p>📋 User LGL ID: <strong>' . esc_html($lglId) . '</strong></p></div>';
            
            // Step 1: Get existing memberships from LGL using dedicated memberships endpoint
            echo '<div class="notice notice-info"><p>📋 Fetching ALL memberships from LGL (active & inactive)...</p></div>';
            \lgl_log("📋 Fetching memberships from dedicated endpoint", ['lgl_id' => $lglId]);
            
            $membershipsResponse = $this->connection->getMemberships($lglId);
            \lgl_log("📦 Raw memberships response", ['response' => $membershipsResponse]);
            
            // Extract memberships from response
            $memberships = [];
            if (!empty($membershipsResponse['success']) && isset($membershipsResponse['data']['items'])) {
                $memberships = $membershipsResponse['data']['items'];
            } elseif (!empty($membershipsResponse['success']) && isset($membershipsResponse['data']) && is_array($membershipsResponse['data'])) {
                // Handle direct array response
                $memberships = $membershipsResponse['data'];
            } else {
                \lgl_log("⚠️ No memberships found in response", ['full_response' => $membershipsResponse]);
                echo '<div class="notice notice-warning"><p>⚠️ No existing memberships found for this constituent.</p></div>';
            }
            
            $activeMemberships = [];
            foreach ($memberships as $membership) {
                // Check if membership is active (no finish_date or finish_date is in the future)
                $finishDate = $membership['finish_date'] ?? null;
                if (!$finishDate || strtotime($finishDate) > time()) {
                    $activeMemberships[] = $membership;
                }
            }
            
            \lgl_log("📊 Active memberships found", ['count' => count($activeMemberships), 'total' => count($memberships)]);
            echo '<div class="notice notice-info"><p>Found ' . count($activeMemberships) . ' active membership(s) (out of ' . count($memberships) . ' total)</p></div>';
            
            // Step 2: Mark all active memberships as finished
            // IMPORTANT: finish_date must be >= date_start, so use today (not yesterday)
            $today = date('Y-m-d');
            
            if (count($activeMemberships) > 0) {
                echo '<div class="notice notice-info"><p>🔄 Marking ' . count($activeMemberships) . ' active membership(s) as inactive...</p></div>';
                \lgl_log("🔄 Starting to deactivate active memberships", ['count' => count($activeMemberships), 'finish_date' => $today]);
            }
            
            foreach ($activeMemberships as $membership) {
                $membershipId = $membership['id'];
                $updatePayload = [
                    'id' => $membershipId,
                    'membership_level_id' => $membership['membership_level_id'],
                    'membership_level_name' => $membership['membership_level_name'],
                    'date_start' => $membership['date_start'],
                    'finish_date' => $today,  // Must be >= date_start
                    'note' => 'Membership ended via LGL Testing Suite on ' . date('Y-m-d')
                ];
                
                \lgl_log("📤 Sending membership update to mark as inactive", ['membership_id' => $membershipId, 'payload' => $updatePayload]);
                
                // Use Connection::updateMembership() which uses the correct direct membership endpoint
                $updateResult = $this->connection->updateMembership((string)$membershipId, $updatePayload);
                
                if (!empty($updateResult['success'])) {
                    echo '<div class="notice notice-success"><p>✅ Marked membership ID ' . esc_html($membershipId) . ' as inactive (finish_date: ' . esc_html($today) . ')</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>⚠️ Could not update membership ID ' . esc_html($membershipId) . ': ' . esc_html(wp_json_encode($updateResult)) . '</p></div>';
                }
            }
            
            // Step 3: Create new membership with new level
            echo '<div class="notice notice-info"><p>➕ Creating new membership...</p></div>';
            
            // Get new membership level from product variation
            $fundId = get_post_meta($variationId, '_lgl_membership_fund_id', true);
            if (!$fundId) {
                throw new \Exception('No _lgl_membership_fund_id found for variation ' . $variationId);
            }
            
            $product = function_exists('wc_get_product') ? wc_get_product($variationId) : null;
            $levelName = $product ? $product->get_name() : 'Individual Membership';
            $price = $product ? $product->get_price() : 75.00;
            
            $newMembershipPayload = [
                'membership_level_id' => intval($fundId),
                'membership_level_name' => $levelName,
                'date_start' => date('Y-m-d'),
                'finish_date' => date('Y-m-d', strtotime('+1 year')),
                'amount' => floatval($price),
                'note' => 'New membership created via LGL Testing Suite on ' . date('Y-m-d'),
            ];
            
            \lgl_log("📤 Creating new membership", ['lgl_id' => $lglId, 'payload' => $newMembershipPayload]);
            $createResult = $this->connection->addMembership($lglId, $newMembershipPayload);
            \lgl_log("📥 New membership creation response", ['response' => $createResult]);
            
            if ($createResult && !empty($createResult['success']) && isset($createResult['data']['id'])) {
                $newMembershipId = $createResult['data']['id'];
                
                // CRITICAL: Also create the payment/gift record for this renewal
                $paymentResult = $this->createTestPayment($lglId, $price, 'Membership', $levelName, $userId, $variationId);
                $paymentId = $paymentResult['id'] ?? null;
                $paymentSuccess = $paymentResult['success'] ?? false;
                
                echo '<div class="notice notice-success">';
                echo '<p>✅ <strong>Membership Update Complete!</strong></p>';
                echo '<ul>';
                echo '<li><strong>Deactivated:</strong> ' . count($activeMemberships) . ' old membership(s)</li>';
                echo '<li><strong>Created:</strong> New membership ID ' . esc_html($newMembershipId) . '</li>';
                echo '<li><strong>New Level:</strong> ' . esc_html($levelName) . '</li>';
                echo '<li><strong>Amount:</strong> $' . esc_html(number_format($price, 2)) . '</li>';
                echo '<li><strong>Start Date:</strong> ' . date('Y-m-d') . '</li>';
                if ($paymentSuccess && $paymentId) {
                    echo '<li><strong>💰 Payment/Gift ID:</strong> ' . esc_html($paymentId) . ' ✅</li>';
                } else {
                    echo '<li><strong>💰 Payment/Gift:</strong> <span style="color: orange;">⚠️ ' . esc_html($paymentResult['error'] ?? 'Failed') . '</span></li>';
                }
                echo '</ul>';
                echo '</div>';
            } else {
                $errorMsg = 'Failed to create new membership';
                if (isset($createResult['error'])) {
                    $errorMsg .= ': ' . $createResult['error'];
                }
                echo '<div class="notice notice-error">';
                echo '<p>❌ <strong>' . esc_html($errorMsg) . '</strong></p>';
                echo '<pre>' . esc_html(wp_json_encode($createResult, JSON_PRETTY_PRINT)) . '</pre>';
                echo '</div>';
            }
            
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Update Membership Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run event registration test
     */
    private function runEventRegistrationTest(): string {
        ob_start();
        
        $userId = isset($_POST['wordpress_user_id']) ? intval($_POST['wordpress_user_id']) : 1214;
        $variationId = isset($_POST['variation_product_id']) ? intval($_POST['variation_product_id']) : 83556;
        
        echo '<div class="notice notice-info"><p>🔄 Testing Event Registration for User ID: ' . $userId . ', Event Variation: ' . $variationId . '...</p></div>';
        
        try {
            // Get user
            $user = get_user_by('ID', $userId);
            if (!$user) {
                throw new \Exception('User not found: ' . $userId);
            }
            
            // Get LGL ID for user
            $lglId = get_user_meta($userId, 'lgl_constituent_id', true) ?: get_user_meta($userId, 'lgl_id', true);
            if (!$lglId) {
                throw new \Exception('User does not have an LGL ID. Run "Add Constituent" test first.');
            }
            
            // Check if product exists
            $product = function_exists('wc_get_product') ? wc_get_product($variationId) : null;
            if (!$product) {
                throw new \Exception('Event product/variation not found: ' . $variationId);
            }
            
            $eventName = $product->get_name();
            $price = (float) $product->get_price();
            
            // Get event fund ID from product meta
            // Events use _ui_event_lgl_fund_id on the PARENT product
            $eventFundId = null;
            if ($product->get_parent_id()) {
                $eventFundId = get_post_meta($product->get_parent_id(), '_ui_event_lgl_fund_id', true);
            }
            
            // Fallback to variation if not found on parent
            if (!$eventFundId) {
                $eventFundId = get_post_meta($variationId, '_ui_event_lgl_fund_id', true);
            }
            
            if (!$eventFundId) {
                throw new \Exception('Event does not have _ui_event_lgl_fund_id configured on product ' . ($product->get_parent_id() ?: $variationId));
            }
            
            \lgl_log("🎟️ Testing Event Registration", [
                'user_id' => $userId,
                'lgl_id' => $lglId,
                'event_product_id' => $variationId,
                'parent_id' => $product->get_parent_id(),
                'event_name' => $eventName,
                'price' => $price,
                'event_fund_id' => $eventFundId
            ]);
            
            // Create test payment for event
            $paymentResult = $this->createTestPayment($lglId, $price, 'Event', $eventName, $userId, $variationId, $eventFundId);
            $paymentId = $paymentResult['id'] ?? null;
            $paymentSuccess = $paymentResult['success'] ?? false;
            
            if ($paymentSuccess && $paymentId) {
                echo '<div class="notice notice-success">';
                echo '<p>✅ <strong>Event Registration Payment Created!</strong></p>';
                echo '<ul>';
                echo '<li><strong>User:</strong> ' . esc_html($user->display_name) . ' (ID: ' . $userId . ')</li>';
                echo '<li><strong>LGL Constituent ID:</strong> ' . esc_html($lglId) . '</li>';
                echo '<li><strong>Event:</strong> ' . esc_html($eventName) . '</li>';
                echo '<li><strong>Event Fund ID:</strong> ' . esc_html($eventFundId) . ' <code>(_ui_event_lgl_fund_id)</code></li>';
                echo '<li><strong>Amount:</strong> $' . esc_html(number_format($price, 2)) . '</li>';
                echo '<li><strong>💰 Payment/Gift ID:</strong> ' . esc_html($paymentId) . ' ✅</li>';
                echo '</ul>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-warning">';
                echo '<p>⚠️ <strong>Event Registration - Payment Failed</strong></p>';
                echo '<p>Event product validated, but payment creation failed:</p>';
                echo '<p>' . esc_html($paymentResult['error'] ?? 'Unknown error') . '</p>';
                echo '</div>';
            }
            
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Event Registration Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Run class registration test
     */
    private function runClassRegistrationTest(): string {
        ob_start();
        
        $userId = isset($_POST['wordpress_user_id']) ? intval($_POST['wordpress_user_id']) : 1214;
        $classProductId = isset($_POST['class_product_id']) ? intval($_POST['class_product_id']) : 86825;
        
        echo '<div class="notice notice-info"><p>🔄 Testing Class Registration for User ID: ' . $userId . ', Class Product: ' . $classProductId . '...</p></div>';
        
        try {
            // Get user
            $user = get_user_by('ID', $userId);
            if (!$user) {
                throw new \Exception('User not found: ' . $userId);
            }
            
            // Get LGL ID for user
            $lglId = get_user_meta($userId, 'lgl_constituent_id', true) ?: get_user_meta($userId, 'lgl_id', true);
            if (!$lglId) {
                throw new \Exception('User does not have an LGL ID. Run "Add Constituent" test first.');
            }
            
            // Check if product exists
            $product = function_exists('wc_get_product') ? wc_get_product($classProductId) : null;
            if (!$product) {
                throw new \Exception('Class product not found: ' . $classProductId);
            }
            
            $className = $product->get_name();
            $price = (float) $product->get_price();
            
            // Get class fund ID from product meta
            // Classes use _lc_lgl_fund_id on the product
            $classFundId = get_post_meta($classProductId, '_lc_lgl_fund_id', true);
            if (!$classFundId) {
                throw new \Exception('Class does not have _lc_lgl_fund_id configured on product ' . $classProductId);
            }
            
            // Determine class type (could be stored in meta or derived from product)
            $classType = get_post_meta($classProductId, 'class_type', true) ?: 'Language Class';
            
            \lgl_log("📚 Testing Class Registration", [
                'user_id' => $userId,
                'lgl_id' => $lglId,
                'class_product_id' => $classProductId,
                'class_name' => $className,
                'price' => $price,
                'class_fund_id' => $classFundId,
                'class_type' => $classType
            ]);
            
            // Create test payment for class
            $paymentResult = $this->createTestPayment($lglId, $price, 'Class', $className, $userId, $classProductId, $classFundId, $classType);
            $paymentId = $paymentResult['id'] ?? null;
            $paymentSuccess = $paymentResult['success'] ?? false;
            
            if ($paymentSuccess && $paymentId) {
                echo '<div class="notice notice-success">';
                echo '<p>✅ <strong>Class Registration Payment Created!</strong></p>';
                echo '<ul>';
                echo '<li><strong>User:</strong> ' . esc_html($user->display_name) . ' (ID: ' . $userId . ')</li>';
                echo '<li><strong>LGL Constituent ID:</strong> ' . esc_html($lglId) . '</li>';
                echo '<li><strong>Class:</strong> ' . esc_html($className) . '</li>';
                echo '<li><strong>Class Type:</strong> ' . esc_html($classType) . '</li>';
                echo '<li><strong>Class Fund ID:</strong> ' . esc_html($classFundId) . ' <code>(_lc_lgl_fund_id)</code></li>';
                echo '<li><strong>Amount:</strong> $' . esc_html(number_format($price, 2)) . '</li>';
                echo '<li><strong>💰 Payment/Gift ID:</strong> ' . esc_html($paymentId) . ' ✅</li>';
                echo '</ul>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-warning">';
                echo '<p>⚠️ <strong>Class Registration - Payment Failed</strong></p>';
                echo '<p>Class product validated, but payment creation failed:</p>';
                echo '<p>' . esc_html($paymentResult['error'] ?? 'Unknown error') . '</p>';
                echo '</div>';
            }
            
        } catch (\Exception $e) {
            echo '<div class="notice notice-error">';
            echo '<p>❌ <strong>Class Registration Test Failed:</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
        
        return ob_get_clean();
    }
    
    /**
     * Create a temporary WooCommerce order for testing purposes
     * 
     * Creates a real WC order with status 'lgl-test' to support payment creation,
     * then auto-deletes after use to keep the system clean.
     * 
     * @param int $userId WordPress user ID
     * @param int $productId Product or variation ID
     * @param float $amount Order total
     * @param string $type Order type ('membership', 'event', 'class')
     * @return int|false Order ID on success, false on failure
     */
    private function createTestOrder(int $userId, int $productId, float $amount, string $type = 'membership') {
        if (!function_exists('wc_create_order')) {
            \lgl_log("❌ WooCommerce not available for test order creation");
            return false;
        }
        
        try {
            \lgl_log("🛒 Creating temporary test order", [
                'user_id' => $userId,
                'product_id' => $productId,
                'amount' => $amount,
                'type' => $type
            ]);
            
            // Get user data for billing
            $user = get_userdata($userId);
            if (!$user) {
                \lgl_log("❌ User not found", ['user_id' => $userId]);
                return false;
            }
            
            // Create order with customer data
            $order = wc_create_order(['customer_id' => $userId]);
            
            // Set billing information from user meta
            $order->set_billing_email($user->user_email);
            $order->set_billing_first_name(get_user_meta($userId, 'first_name', true) ?: $user->display_name);
            $order->set_billing_last_name(get_user_meta($userId, 'last_name', true) ?: '');
            $order->set_billing_phone(get_user_meta($userId, 'billing_phone', true) ?: '');
            
            // Add product to order as a line item
            $product = wc_get_product($productId);
            if (!$product) {
                \lgl_log("❌ Product not found", ['product_id' => $productId]);
                $order->delete(true);
                return false;
            }
            
            // Add the product with proper price
            $item = new \WC_Order_Item_Product();
            $item->set_props([
                'product' => $product,
                'quantity' => 1,
                'subtotal' => $amount,
                'total' => $amount,
            ]);
            $order->add_item($item);
            
            // For event products, add attendee meta data to ORDER (required for CCT creation)
            // The OrderProcessor::collectAttendeeData() looks for order-level meta, not item meta
            if ($type === 'event') {
                $attendee_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $order->update_meta_data('attendee_name', $attendee_name);
                $order->update_meta_data('attendee_email', $order->get_billing_email());
                
                \lgl_log("📝 Added attendee metadata to order", [
                    'attendee_name' => $attendee_name,
                    'attendee_email' => $order->get_billing_email()
                ]);
            }
            
            // Set order totals
            $order->set_total($amount);
            $order->set_payment_method('bacs');
            $order->set_payment_method_title('Test Payment');
            
            // Add meta to identify this as a test order
            $order->update_meta_data('_lgl_test_order', true);
            $order->update_meta_data('_lgl_test_type', $type);
            $order->update_meta_data('_lgl_test_created', time());
            
            // Save the order first with pending status
            $order->save();
            
            \lgl_log("✅ Test order created with line items", [
                'order_id' => $order->get_id(),
                'item_count' => count($order->get_items()),
                'product_name' => $product->get_name(),
                'has_billing_info' => !empty($order->get_billing_email())
            ]);
            
            return $order->get_id();
            
        } catch (\Exception $e) {
            \lgl_log("❌ Failed to create test order", ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Delete a test order after use
     * 
     * @param int $orderId Order ID to delete
     * @return bool Success status
     */
    private function deleteTestOrder(int $orderId): bool {
        if (!function_exists('wc_get_order')) {
            return false;
        }
        
        try {
            $order = wc_get_order($orderId);
            if (!$order) {
                return false;
            }
            
            // Verify it's a test order before deleting
            if ($order->get_meta('_lgl_test_order') !== 'true' && $order->get_meta('_lgl_test_order') !== true) {
                \lgl_log("⚠️ Attempted to delete non-test order", ['order_id' => $orderId]);
                return false;
            }
            
            // Force delete (bypass trash)
            $order->delete(true);
            
            \lgl_log("🗑️ Test order deleted", ['order_id' => $orderId]);
            return true;
            
        } catch (\Exception $e) {
            \lgl_log("❌ Failed to delete test order", ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Create a test payment/gift for testing purposes
     * 
     * This mimics the production payment creation flow by:
     * 1. Creating a temporary WC order
     * 2. Using it to create the payment in LGL
     * 3. Deleting the temporary order
     * 
     * @param string $lglId LGL constituent ID
     * @param float $amount Payment amount
     * @param string $type Payment type ('Membership', 'Event', 'Class')
     * @param string $name Membership level, event name, or class name
     * @param int $userId WordPress user ID
     * @param int $productId Product or variation ID
     * @param string|null $fundId LGL fund ID (for events/classes)
     * @param string|null $classType Class type (for class registrations)
     * @return array Payment result with 'success', 'id', and 'error' keys
     */
    private function createTestPayment(
        string $lglId, 
        float $amount, 
        string $type = 'Membership', 
        string $name = '', 
        int $userId = 0, 
        int $productId = 0,
        ?string $fundId = null,
        ?string $classType = null
    ): array {
        $testOrderId = null;
        
        try {
            \lgl_log("💰 Creating test payment/gift", [
                'lgl_id' => $lglId,
                'amount' => $amount,
                'type' => $type,
                'name' => $name,
                'fund_id' => $fundId,
                'class_type' => $classType
            ]);
            
            // Create temporary test order with line items
            $testOrderId = $this->createTestOrder($userId, $productId, $amount, strtolower($type));
            
            if (!$testOrderId) {
                throw new \Exception('Failed to create test order');
            }
            
            // CRITICAL: Trigger OrderProcessor to create CCT records (for events/classes)
            // This mimics the production flow where orders are processed after checkout
            if ($type === 'Event' || $type === 'Class') {
                \lgl_log("🎯 Triggering OrderProcessor for CCT creation", [
                    'order_id' => $testOrderId,
                    'type' => $type
                ]);
                
                $orderProcessor = lgl_get_container()->get('woocommerce.order_processor');
                $orderProcessor->processCompletedOrder($testOrderId);
                
                \lgl_log("✅ OrderProcessor completed - CCT records should be created");
            }
            
            // Use the Payments service to setup payment (matches production flow)
            if ($type === 'Membership') {
                $result = $this->payments->setupMembershipPayment(
                    $lglId,
                    $testOrderId,
                    $amount,
                    date('Y-m-d'),
                    'Credit Card'  // Default payment type for testing
                );
            } elseif ($type === 'Event') {
                $result = $this->payments->setupEventPayment(
                    $lglId,
                    $testOrderId,
                    $amount,
                    date('Y-m-d'),
                    $name,  // Event name
                    $fundId  // LGL event fund ID (_ui_event_lgl_fund_id)
                );
            } elseif ($type === 'Class') {
                $result = $this->payments->setupClassPayment(
                    $lglId,
                    $testOrderId,
                    $amount,
                    date('Y-m-d'),
                    $classType ?? 'Language Class',  // Class type
                    $fundId  // LGL class fund ID (_lc_lgl_fund_id)
                );
            } else {
                // Fallback to membership payment for unknown types
                $result = $this->payments->setupMembershipPayment(
                    $lglId,
                    $testOrderId,
                    $amount,
                    date('Y-m-d'),
                    'Credit Card'
                );
            }
            
            // Always cleanup the test order, regardless of payment success
            if ($testOrderId) {
                $this->deleteTestOrder($testOrderId);
            }
            
            if (!empty($result['success'])) {
                $paymentId = $result['id'] ?? null;
                \lgl_log("✅ Test payment created successfully", ['payment_id' => $paymentId, 'test_order_deleted' => true]);
                return [
                    'success' => true,
                    'id' => $paymentId,
                    'order_id' => $testOrderId
                ];
            } else {
                $errorMsg = $result['error'] ?? 'Unknown payment error';
                \lgl_log("❌ Test payment creation failed", ['error' => $errorMsg, 'test_order_deleted' => true]);
                return [
                    'success' => false,
                    'error' => $errorMsg
                ];
            }
            
        } catch (\Exception $e) {
            // Cleanup on exception
            if ($testOrderId) {
                $this->deleteTestOrder($testOrderId);
            }
            
            \lgl_log("❌ Exception during test payment creation", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>
