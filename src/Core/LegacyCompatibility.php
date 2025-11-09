<?php
/**
 * Legacy Compatibility Layer
 * 
 * Provides backward compatibility for legacy code that still depends on
 * the old LGL_API class and direct function calls.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Core;

/**
 * LegacyCompatibility Class
 * 
 * Maintains compatibility with existing integrations
 */
class LegacyCompatibility {
    
    /**
     * Initialize legacy compatibility layer
     * 
     * @return void
     */
    public static function initialize(): void {
        // Load essential legacy classes for compatibility
        self::loadLegacyClasses();
        
        // Register legacy shortcodes
        self::registerLegacyShortcodes();
        
        // Maintain legacy hook compatibility
        self::maintainHookCompatibility();
    }
    
    /**
     * Load essential legacy classes
     * 
     * @return void
     */
    private static function loadLegacyClasses(): void {
        $legacyIncludes = [
            // CRITICAL: Load the legacy API bridge first (provides LGL_API class)
            'includes/lgl-api-legacy-bridge.php',  // Legacy LGL_API bridge
            
            // Core legacy classes that may still be directly referenced
            'includes/decrease_registration_counter_on_trash.php',
            'includes/ui_memberships/ui_memberships.php',
            
            // Backward compatibility includes (legacy versions alongside modern equivalents)
            'includes/lgl-connections.php',        // Legacy LGL_Connect
            'includes/lgl-wp-users.php',           // Legacy LGL_WP_Users
            'includes/lgl-constituents.php',       // Legacy LGL_Constituents
            'includes/lgl-payments.php',           // Legacy LGL_Payments
            'includes/lgl-relations-manager.php',  // Legacy LGL_Relations_Manager
            'includes/lgl-helper.php',             // Legacy LGL_Helper
            'includes/lgl-api-settings.php',       // Legacy LGL_API_Settings (needed by some legacy classes)
            'includes/test_requests.php',          // Legacy Test_Requests
            'includes/admin/dashboard-widgets.php', // Legacy LGL_Dashboard_Widgets
            'includes/email/daily-email.php',      // Legacy LGL_Daily_Email
            // 'includes/email/email-blocker.php',    // REMOVED: Modern version in src/Email/EmailBlocker.php is now used
            'includes/woocommerce/subscription-renewal.php', // Legacy LGL_Subscription_Renewal
            'includes/lgl-cache-manager.php',      // Legacy LGL_Cache_Manager
            'includes/lgl-utilities.php',          // Legacy LGL_Utilities
        ];
        
        foreach ($legacyIncludes as $include) {
            $file = LGL_PLUGIN_DIR . $include;
            if (file_exists($file)) {
                require_once $file;
            }
        }
        
        // Note: LGL_API class will be auto-initialized when needed via the bridge
    }
    
    /**
     * Register legacy shortcodes for backward compatibility
     * 
     * @return void
     */
    private static function registerLegacyShortcodes(): void {
        add_action('template_redirect', function() {
            // Legacy LGL shortcode
            if (class_exists('\LGL_API')) {
                $lgl = \LGL_API::get_instance();
                if (method_exists($lgl, 'shortcode_init')) {
                    $lgl->shortcode_init();
                }
            }
        }, 10);
        
        add_action('template_redirect', function() {
            // Legacy membership shortcodes
            if (class_exists('\LGL_WP_Users')) {
                $lgl_users = \LGL_WP_Users::get_instance();
                if (method_exists($lgl_users, 'shortcode_init')) {
                    $lgl_users->shortcode_init();
                }
            }
        }, 10);
        
        add_action('template_redirect', function() {
            // Legacy UI memberships shortcode
            if (class_exists('\UI_Memberships')) {
                $ui_members = \UI_Memberships::get_instance();
                if (method_exists($ui_members, 'shortcode_init')) {
                    $ui_members->shortcode_init();
                }
            }
        }, 10);
    }
    
    /**
     * Maintain hook compatibility for any remaining legacy integrations
     * 
     * @return void
     */
    private static function maintainHookCompatibility(): void {
        // Only register legacy hooks if modern architecture is not handling them
        add_action('init', function() {
            // Check if modern architecture is active
            $modernActive = class_exists('\UpstateInternational\LGL\Core\Plugin');
            
            if (!$modernActive) {
                // Fallback to legacy hook registrations
                self::registerFallbackHooks();
            }
        }, 15);
    }
    
    /**
     * Register fallback hooks if modern architecture fails
     * 
     * @return void
     */
    private static function registerFallbackHooks(): void {
        if (!class_exists('\LGL_API') || !class_exists('\LGL_WP_Users')) {
            return;
        }
        
        // Get instances only when needed
        $lgl_api = \LGL_API::get_instance();
        $lgl_users = \LGL_WP_Users::get_instance();
        
        // JetFormBuilder actions (fallback only)
        add_action('jet-form-builder/custom-action/lgl_check_abandoned_users', [$lgl_users, 'check_abandoned_users'], 10, 2);
        add_action('jet-form-builder/custom-action/lgl_register_user', [$lgl_api, 'lgl_register_user'], 10, 2);
        add_action('jet-form-builder/custom-action/lgl_add_class_registration', [$lgl_api, 'lgl_add_class_registration'], 10, 2);
        add_action('jet-form-builder/custom-action/lgl_update_membership', [$lgl_api, 'lgl_update_membership'], 10, 2);
        add_action('jet-form-builder/custom-action/lgl_renew_membership', [$lgl_api, 'lgl_renew_membership'], 10, 2);
        add_action('jet-form-builder/custom-action/lgl_edit_user', [$lgl_api, 'lgl_edit_user'], 10, 2);
        add_action('jet-form-builder/custom-action/lgl_add_family_member', [$lgl_api, 'lgl_add_family_member'], 10, 2);
        add_action('jet-form-builder/custom-action/lgl_deactivate_membership', [$lgl_api, 'lgl_deactivate_membership'], 13, 2);
        add_action('jet-form-builder/custom-action/ui_family_user_deactivation', [$lgl_users, 'ui_family_user_deactivation'], 13, 2);
        
        // WooCommerce hooks (fallback only)
        add_action('woocommerce_subscription_status_cancelled', [$lgl_api, 'update_user_meta_on_subscription_cancel'], 10, 1);
        add_action('woocommerce_subscription_status_updated', [$lgl_api, 'update_user_subscription_status'], 10, 3);
        add_action('woocommerce_email_before_order_table', [$lgl_api, 'custom_email_content_for_category'], 10, 4);
        
        Helper::getInstance()->debug('LGL Plugin: Fallback legacy hooks registered (modern architecture not available)');
    }
    
    /**
     * Provide legacy function wrappers
     * 
     * @return void
     */
    public static function provideLegacyWrappers(): void {
        // Legacy settings access wrapper
        if (!function_exists('lgl_get_setting')) {
            function lgl_get_setting($key, $default = null) {
                if (function_exists('lgl_plugin') && lgl_plugin()) {
                    $settingsHandler = lgl_plugin()->getServiceFromContainer('admin.settings_handler');
                    if ($settingsHandler) {
                        return $settingsHandler->getSetting($key, $default);
                    }
                }
                
                // Fallback to Carbon Fields
                if (function_exists('carbon_get_theme_option')) {
                    return carbon_get_theme_option($key) ?: $default;
                }
                
                return $default;
            }
        }
        
        // Legacy cache wrapper
        if (!function_exists('lgl_cache_get')) {
            function lgl_cache_get($key, $default = null) {
                if (function_exists('lgl_plugin') && lgl_plugin()) {
                    $cache = lgl_plugin()->getServiceFromContainer('cache.manager');
                    if ($cache && method_exists($cache, 'get')) {
                        return $cache->get($key, $default);
                    }
                }
                
                return get_transient($key) ?: $default;
            }
        }
        
        if (!function_exists('lgl_cache_set')) {
            function lgl_cache_set($key, $value, $ttl = 3600) {
                if (function_exists('lgl_plugin') && lgl_plugin()) {
                    $cache = lgl_plugin()->getServiceFromContainer('cache.manager');
                    if ($cache && method_exists($cache, 'set')) {
                        return $cache->set($key, $value, $ttl);
                    }
                }
                
                return set_transient($key, $value, $ttl);
            }
        }
    }
    
    /**
     * Initialize cache invalidation (legacy support)
     * 
     * @return void
     */
    public static function initializeCacheInvalidation(): void {
        add_action('init', function() {
            if (class_exists('\UpstateInternational\LGL\Core\CacheManager')) {
                \UpstateInternational\LGL\Core\CacheManager::initCacheInvalidation();
            } elseif (class_exists('\LGL_Cache_Manager')) {
                \LGL_Cache_Manager::init_cache_invalidation();
            }
        });
    }
}
