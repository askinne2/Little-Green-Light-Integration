<?php
/**
 * Legacy Settings Adapter
 * 
 * Provides backward compatibility for the old LGL_API_Settings class
 * while redirecting functionality to the modern SettingsManager.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\Admin\SettingsManager;

/**
 * LegacySettingsAdapter Class
 * 
 * Maintains compatibility with existing code that uses LGL_API_Settings
 */
class LegacySettingsAdapter {
    
    /**
     * Modern settings manager instance
     * 
     * @var SettingsManager
     */
    private SettingsManager $settingsManager;
    
    /**
     * Legacy instance
     * 
     * @var LegacySettingsAdapter|null
     */
    private static $instance = null;
    
    /**
     * Constructor
     * 
     * @param SettingsManager $settingsManager Modern settings manager
     */
    public function __construct(SettingsManager $settingsManager) {
        $this->settingsManager = $settingsManager;
    }
    
    /**
     * Get legacy instance (for backward compatibility)
     * 
     * @return LegacySettingsAdapter
     */
    public static function get_instance(): LegacySettingsAdapter {
        if (is_null(self::$instance)) {
            // Get modern settings handler from service container
            if (function_exists('lgl_plugin')) {
                $plugin = lgl_plugin();
                $settingsHandler = $plugin->getServiceFromContainer('admin.settings_handler');
                self::$instance = new self($settingsHandler);
            } else {
                // Fallback - this shouldn't happen in modern setup
                throw new \Exception('LGL Plugin not properly initialized');
            }
        }
        
        return self::$instance;
    }
    
    /**
     * Initialize legacy settings (compatibility method)
     * 
     * @return void
     */
    public function lgl_init(): void {
        // Legacy method - functionality now handled by modern SettingsManager
        // This method is kept for backward compatibility but does nothing
        // as initialization is handled by the modern architecture
    }
    
    /**
     * Get setting value (legacy compatibility)
     * 
     * @param string $setting_name Setting key
     * @return mixed
     */
    public function lgl_get_setting(string $setting_name) {
        return $this->settingsManager->getSetting($setting_name);
    }
    
    /**
     * Debug method (legacy compatibility)
     * 
     * @param string $string Debug label
     * @param mixed $data Debug data
     * @return void
     */
    public function debug(string $string, $data = null): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("LGL Debug [{$string}]: " . print_r($data, true));
        }
    }
    
    /**
     * Legacy settings page method (no longer used)
     * 
     * @return void
     */
    public function lgl_settings_page(): void {
        // Legacy method - settings page is now handled by modern SettingsManager
        // This method is kept for backward compatibility but does nothing
    }
    
    /**
     * Legacy membership fields method (no longer used)
     * 
     * @return void
     */
    public function set_membership_fields(): void {
        // Legacy method - membership sync is now handled by modern SettingsManager
        // This method is kept for backward compatibility but does nothing
    }
}

// Global backward compatibility functions
if (!function_exists('lgl_api_settings')) {
    /**
     * Legacy initialization function
     * 
     * @return void
     */
    function lgl_api_settings(): void {
        // This function is no longer needed as settings are initialized
        // through the modern Plugin architecture, but we keep it for
        // backward compatibility
    }
}

// Legacy class alias for maximum backward compatibility
if (!class_exists('LGL_API_Settings')) {
    /**
     * Legacy class alias
     * 
     * Provides backward compatibility for code that directly instantiates LGL_API_Settings
     */
    class LGL_API_Settings extends LegacySettingsAdapter {
        
        /**
         * Get instance (legacy method)
         * 
         * @return LGL_API_Settings
         */
        public static function get_instance(): LGL_API_Settings {
            return parent::get_instance();
        }
    }
}
