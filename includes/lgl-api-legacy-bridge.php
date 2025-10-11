<?php
/**
 * Legacy API Bridge (Minimal Version)
 * 
 * Provides backward compatibility for the old LGL_API class interface.
 * This minimal version avoids circular dependencies during initialization.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Legacy compatibility constants (if not already defined)
if (!defined('PLUGIN_DEBUG')) define('PLUGIN_DEBUG', false);
if (!defined('BUFFER')) define('BUFFER', true);
if (!defined('REMOVE_TRANSIENT')) define('REMOVE_TRANSIENT', false);
if (!defined('LGL_DATA_DELAY')) define('LGL_DATA_DELAY', 0.1);

if (!class_exists('LGL_API')) {
    /**
     * Legacy LGL_API Bridge Class
     * 
     * Minimal implementation that provides backward compatibility
     * without causing circular dependency issues.
     */
    class LGL_API {
        
        /**
         * Class instance
         * @var null|LGL_API
         */
        private static $instance = null;
        
        /**
         * Legacy helper instance
         * @var mixed
         */
        public $helper = null;
        
        /**
         * Legacy settings instance
         * @var mixed
         */
        public $settings = null;
        
        /**
         * Legacy constituents instance
         * @var mixed
         */
        public $constituents = null;
        
        /**
         * Legacy payments instance
         * @var mixed
         */
        public $payments = null;
        
        /**
         * Legacy constants for backward compatibility
         */
        const UI_DELETE_MEMBERS = 'ui_members_monthly_hook';
        
        /**
         * Get instance (singleton pattern)
         * 
         * @return LGL_API
         */
        public static function get_instance() {
            if (is_null(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         * Constructor - Minimal initialization to avoid circular dependencies
         */
        public function __construct() {
            // Delay initialization to avoid circular dependencies
            add_action('init', [$this, 'delayed_initialization'], 15);
        }
        
        /**
         * Delayed initialization to avoid circular dependencies
         */
        public function delayed_initialization() {
            // Initialize legacy components after WordPress is fully loaded
            $this->initializeLegacyComponents();
        }
        
        /**
         * Initialize legacy components safely
         */
        private function initializeLegacyComponents() {
            // Initialize helper
            if (class_exists('LGL_Helper')) {
                $this->helper = LGL_Helper::get_instance();
            }
            
            // Initialize settings (fallback to legacy if modern not available)
            if (class_exists('LGL_API_Settings')) {
                $this->settings = LGL_API_Settings::get_instance();
            }
            
            // Initialize constituents
            if (class_exists('LGL_Constituents')) {
                $this->constituents = LGL_Constituents::get_instance();
            }
            
            // Initialize payments
            if (class_exists('LGL_Payments')) {
                $this->payments = LGL_Payments::get_instance();
            }
        }
        
        /**
         * Initialize shortcodes
         */
        public function shortcode_init() {
            add_shortcode('lgl', [$this, 'run_update']);
        }
        
        /**
         * Shortcode handler - Simple fallback implementation
         */
        public function run_update($atts) {
            // Check if modern architecture can handle this
            if (function_exists('lgl_plugin') && class_exists('\UpstateInternational\LGL\Core\Plugin')) {
                try {
                    $modernPlugin = lgl_plugin();
                    if ($modernPlugin && method_exists($modernPlugin, 'getServiceFromContainer')) {
                        $shortcodeService = $modernPlugin->getServiceFromContainer('shortcodes.lgl');
                        if ($shortcodeService && method_exists($shortcodeService, 'handle')) {
                            return $shortcodeService->handle($atts);
                        }
                    }
                } catch (Exception $e) {
                    error_log('LGL Legacy Bridge: Error delegating to modern architecture: ' . $e->getMessage());
                }
            }
            
            // Fallback implementation
            return '<p>LGL Debug: Legacy shortcode active</p>';
        }
        
        // Legacy method stubs - Basic implementations to prevent fatal errors
        
        public function lgl_register_user($request, $action_handler) {
            error_log('🔗 LGL Legacy Bridge: lgl_register_user() CALLED with data: ' . print_r($request, true));
            
            // Check if modern architecture can handle this
            if (function_exists('lgl_plugin') && class_exists('\UpstateInternational\LGL\Core\Plugin')) {
                try {
                    error_log('🚀 LGL Legacy Bridge: Attempting modern architecture delegation...');
                    $modernPlugin = lgl_plugin();
                    if ($modernPlugin && method_exists($modernPlugin, 'getServiceFromContainer')) {
                        $userRegistrationAction = $modernPlugin->getServiceFromContainer('jetformbuilder.user_registration_action');
                        if ($userRegistrationAction && method_exists($userRegistrationAction, 'handle')) {
                            error_log('✅ LGL Legacy Bridge: Delegating to modern UserRegistrationAction...');
                            return $userRegistrationAction->handle($request, $action_handler);
                        }
                    }
                } catch (Exception $e) {
                    error_log('❌ LGL Legacy Bridge: Modern delegation failed: ' . $e->getMessage());
                }
            }
            
            // Fallback: Log that we received the request but can't process it
            error_log('⚠️ LGL Legacy Bridge: No modern implementation available - registration request logged but not processed');
            error_log('📋 LGL Legacy Bridge: Request data: ' . json_encode($request, JSON_PRETTY_PRINT));
            
            return false;
        }
        
        public function lgl_add_class_registration($request, $action_handler) {
            error_log('LGL Legacy Bridge: lgl_add_class_registration called - modern implementation recommended');
        }
        
        public function lgl_update_membership($request, $action_handler) {
            error_log('LGL Legacy Bridge: lgl_update_membership called - modern implementation recommended');
        }
        
        public function lgl_renew_membership($request, $action_handler) {
            error_log('LGL Legacy Bridge: lgl_renew_membership called - modern implementation recommended');
        }
        
        public function lgl_edit_user($request, $action_handler) {
            error_log('LGL Legacy Bridge: lgl_edit_user called - modern implementation recommended');
        }
        
        public function lgl_add_family_member($request, $action_handler) {
            error_log('LGL Legacy Bridge: lgl_add_family_member called - modern implementation recommended');
        }
        
        public function lgl_deactivate_membership($request, $action_handler) {
            error_log('LGL Legacy Bridge: lgl_deactivate_membership called - modern implementation recommended');
        }
        
        public function update_user_meta_on_subscription_cancel($subscription_id) {
            error_log('LGL Legacy Bridge: update_user_meta_on_subscription_cancel called - modern implementation recommended');
        }
        
        public function update_user_subscription_status($subscription, $status_to, $status_from) {
            error_log('LGL Legacy Bridge: update_user_subscription_status called - modern implementation recommended');
        }
        
        public function custom_email_content_for_category($order, $sent_to_admin, $plain_text, $email) {
            error_log('LGL Legacy Bridge: custom_email_content_for_category called - modern implementation recommended');
        }
    }
}