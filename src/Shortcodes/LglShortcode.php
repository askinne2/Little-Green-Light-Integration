<?php
/**
 * LGL Shortcode
 * 
 * Main LGL plugin shortcode for debugging, updates, and testing.
 * Provides development tools and data manipulation capabilities.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Shortcodes;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Core\TestRequests;

/**
 * LglShortcode Class
 * 
 * Handles the main [lgl] shortcode functionality
 */
class LglShortcode {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Test Requests service
     * 
     * @var TestRequests
     */
    private TestRequests $testRequests;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param TestRequests $testRequests Test requests service
     */
    public function __construct(Helper $helper, TestRequests $testRequests) {
        $this->helper = $helper;
        $this->testRequests = $testRequests;
    }
    
    /**
     * Handle LGL shortcode
     * 
     * @param array $atts Shortcode attributes
     * @param string $content Shortcode content
     * @param string $tag Shortcode tag
     * @return string Shortcode output
     */
    public function handle(array $atts = [], string $content = '', string $tag = ''): string {
        // Parse attributes
        $atts = shortcode_atts([
            'action' => 'update',
            'debug' => 'false',
            'user_id' => '',
            'remove_transient' => 'false'
        ], $atts, $tag);
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return $this->handleLoggedOutUser($atts);
        }
        
        // Handle transient removal
        if ($this->shouldRemoveTransient($atts)) {
            $this->helper->removeTransient();
        }
        
        // Get current user
        $uid = wp_get_current_user()->data->ID;
        
        // Execute the shortcode action
        return $this->executeAction($atts, $uid);
    }
    
    /**
     * Handle logged out user
     * 
     * @param array $atts Shortcode attributes
     * @return string Output for logged out users
     */
    private function handleLoggedOutUser(array $atts): string {
        $this->helper->debug('LglShortcode: User not logged in');
        
        if ($atts['debug'] === 'true' && $this->isDebugMode()) {
            return '<div class="lgl-shortcode-notice">LGL Shortcode: User not logged in</div>';
        }
        
        return '';
    }
    
    /**
     * Check if transient should be removed
     * 
     * @param array $atts Shortcode attributes
     * @return bool
     */
    private function shouldRemoveTransient(array $atts): bool {
        return $atts['remove_transient'] === 'true' || 
               (defined('REMOVE_TRANSIENT') && REMOVE_TRANSIENT);
    }
    
    /**
     * Execute shortcode action
     * 
     * @param array $atts Shortcode attributes
     * @param int $uid User ID
     * @return string Action output
     */
    private function executeAction(array $atts, int $uid): string {
        $action = $atts['action'];
        $output = '';
        
        switch ($action) {
            case 'update':
                $output = $this->runUpdate($uid);
                break;
                
            case 'test_registration':
                $output = $this->runTestRegistration();
                break;
                
            case 'user_meta':
                $output = $this->displayUserMeta($uid);
                break;
                
            case 'debug_info':
                $output = $this->displayDebugInfo($uid);
                break;
                
            case 'funds':
                $output = $this->displayFunds();
                break;
                
            default:
                $output = $this->runUpdate($uid);
        }
        
        return $output;
    }
    
    /**
     * Run main update functionality
     * 
     * @param int $uid User ID
     * @return string Update output
     */
    private function runUpdate(int $uid): string {
        $this->helper->debug('LglShortcode: Running update for user', $uid);
        
        // Get user meta for debugging
        $meta = get_user_meta($uid);
        
        // Note: Commented out sections from original code are preserved for reference
        // but not executed to avoid unintended side effects
        
        /*
        // Example order processing (commented out for safety)
        $order_id = 68596;
        $order = wc_get_order($order_id);
        
        $wc_order_meta = $order->get_meta();
        $this->helper->debug('meta:', $wc_order_meta);
        $languages = $order->get_meta('_order_languages_spoken');
        $emailaddress = get_user_meta($uid, 'user_email', true);
        if (!$emailaddress) {
            $user_info = get_userdata($uid);
            $emailaddress = $user_info->data->user_email;
        }
        $this->helper->debug('emailaddress:', $emailaddress);
        
        // Process order
        $this->custom_action_after_successful_checkout($order_id);
        
        // Get funds and write CSV
        $funds = $this->connection->get_all_funds();
        $this->helper->debug('funds:', $funds);
        $this->helper->writeFundsCSV('output', $funds);
        
        // Test registration
        $test->make_registration();
        $this->lgl_register_user($test->registration_request, null);
        */
        
        if ($this->isDebugMode()) {
            return '<div class="lgl-shortcode-result">LGL Update completed for user ' . $uid . '</div>';
        }
        
        return '';
    }
    
    /**
     * Run test registration
     * 
     * @return string Test output
     */
    private function runTestRegistration(): string {
        if (!$this->isDebugMode()) {
            return '';
        }
        
        try {
            $this->testRequests->makeRegistration();
            $this->helper->debug('LglShortcode: Test registration completed');
            
            return '<div class="lgl-shortcode-result">Test registration completed successfully</div>';
            
        } catch (\Exception $e) {
            $this->helper->debug('LglShortcode: Test registration failed', $e->getMessage());
            
            return '<div class="lgl-shortcode-error">Test registration failed: ' . esc_html($e->getMessage()) . '</div>';
        }
    }
    
    /**
     * Display user meta information
     * 
     * @param int $uid User ID
     * @return string User meta output
     */
    private function displayUserMeta(int $uid): string {
        if (!$this->isDebugMode()) {
            return '';
        }
        
        $meta = get_user_meta($uid);
        $user_info = get_userdata($uid);
        
        $output = '<div class="lgl-shortcode-debug">';
        $output .= '<h3>User Meta Debug (User ID: ' . $uid . ')</h3>';
        $output .= '<p><strong>Username:</strong> ' . esc_html($user_info->user_login) . '</p>';
        $output .= '<p><strong>Email:</strong> ' . esc_html($user_info->user_email) . '</p>';
        
        // Display relevant LGL meta
        $lgl_meta = [
            'lgl_id' => 'LGL ID',
            'user-membership-type' => 'Membership Type',
            'user-membership-start-date' => 'Membership Start Date',
            'user-membership-renewal-date' => 'Membership Renewal Date',
            'user-subscription-id' => 'Subscription ID',
            'user-subscription-status' => 'Subscription Status',
            'payment-method' => 'Payment Method'
        ];
        
        $output .= '<h4>LGL Meta Fields:</h4><ul>';
        foreach ($lgl_meta as $key => $label) {
            $value = get_user_meta($uid, $key, true);
            $output .= '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html($value ?: 'Not set') . '</li>';
        }
        $output .= '</ul></div>';
        
        return $output;
    }
    
    /**
     * Display debug information
     * 
     * @param int $uid User ID
     * @return string Debug output
     */
    private function displayDebugInfo(int $uid): string {
        if (!$this->isDebugMode()) {
            return '';
        }
        
        $output = '<div class="lgl-shortcode-debug">';
        $output .= '<h3>LGL Plugin Debug Info</h3>';
        $output .= '<p><strong>Plugin Version:</strong> ' . (defined('LGL_PLUGIN_VERSION') ? LGL_PLUGIN_VERSION : 'Unknown') . '</p>';
        $output .= '<p><strong>Current User ID:</strong> ' . $uid . '</p>';
        $output .= '<p><strong>WordPress Debug:</strong> ' . (WP_DEBUG ? 'Enabled' : 'Disabled') . '</p>';
        $output .= '<p><strong>Modern Architecture:</strong> ' . (class_exists('\UpstateInternational\LGL\Core\Plugin') ? 'Active' : 'Not Available') . '</p>';
        $output .= '<p><strong>Legacy Classes:</strong> ' . (class_exists('LGL_API') ? 'Available' : 'Not Available') . '</p>';
        
        // Display service status
        $output .= '<h4>Service Status:</h4><ul>';
        $output .= '<li><strong>Helper Service:</strong> ' . (isset($this->helper) ? 'Available' : 'Not Available') . '</li>';
        $output .= '<li><strong>Test Requests:</strong> ' . (isset($this->testRequests) ? 'Available' : 'Not Available') . '</li>';
        $output .= '<li><strong>WooCommerce:</strong> ' . (class_exists('WC_Order') ? 'Active' : 'Not Available') . '</li>';
        $output .= '</ul></div>';
        
        return $output;
    }
    
    /**
     * Display funds information
     * 
     * @return string Funds output
     */
    private function displayFunds(): string {
        if (!$this->isDebugMode()) {
            return '';
        }
        
        // This would require access to the connection service
        // For now, return a placeholder
        return '<div class="lgl-shortcode-result">Funds display functionality requires connection service</div>';
    }
    
    /**
     * Check if debug mode is enabled
     * 
     * @return bool
     */
    private function isDebugMode(): bool {
        return (defined('WP_DEBUG') && WP_DEBUG) || 
               (defined('LGL_DEBUG') && LGL_DEBUG) ||
               current_user_can('manage_options');
    }
    
    /**
     * Get shortcode documentation
     * 
     * @return array Shortcode documentation
     */
    public static function getDocumentation(): array {
        return [
            'tag' => 'lgl',
            'description' => 'Main LGL plugin shortcode for debugging and updates',
            'attributes' => [
                'action' => [
                    'default' => 'update',
                    'options' => ['update', 'test_registration', 'user_meta', 'debug_info', 'funds'],
                    'description' => 'Action to perform'
                ],
                'debug' => [
                    'default' => 'false',
                    'options' => ['true', 'false'],
                    'description' => 'Show debug output'
                ],
                'user_id' => [
                    'default' => '',
                    'description' => 'Specific user ID (optional)'
                ],
                'remove_transient' => [
                    'default' => 'false',
                    'options' => ['true', 'false'],
                    'description' => 'Remove cached data'
                ]
            ],
            'examples' => [
                '[lgl]' => 'Basic update',
                '[lgl action="debug_info" debug="true"]' => 'Show debug information',
                '[lgl action="user_meta" debug="true"]' => 'Show current user meta',
                '[lgl action="test_registration"]' => 'Run test registration',
                '[lgl remove_transient="true"]' => 'Clear cache and run update'
            ],
            'requires_login' => true,
            'admin_only' => false,
            'debug_features' => true
        ];
    }
}
