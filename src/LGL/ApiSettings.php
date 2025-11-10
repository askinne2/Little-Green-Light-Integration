<?php
/**
 * LGL API Settings Manager
 * 
 * Legacy settings accessor - maintained for backward compatibility.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 * @deprecated 2.1.0 Use SettingsManager instead via dependency injection
 */

namespace UpstateInternational\LGL\LGL;

/**
 * API Settings Class
 * 
 * @deprecated 2.1.0 Use UpstateInternational\LGL\Admin\SettingsManager instead
 * 
 * Legacy wrapper that delegates to modern SettingsManager service.
 * Kept for backward compatibility with existing code.
 */
class ApiSettings {
    
    /**
     * Class instance
     * 
     * @var ApiSettings|null
     */
    private static $instance = null;
    
    /**
     * Settings cache
     * 
     * @var array
     */
    private $settingsCache = [];
    
    /**
     * Modern settings handler (delegates to this for new settings)
     * 
     * @var \UpstateInternational\LGL\Admin\SettingsHandler|null
     */
    private $settingsHandler = null;
    
    /**
     * Modern settings manager (preferred for all settings access)
     * 
     * @var \UpstateInternational\LGL\Admin\SettingsManager|null
     */
    private $settingsManager = null;
    
    /**
     * Get instance
     * 
     * @return ApiSettings
     * @deprecated 2.1.0 Use SettingsManager via dependency injection instead
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
        $this->initializeSettings();
        // Note: SettingsManager will be lazy-loaded when first needed to avoid circular dependency
    }
    
    /**
     * Set modern settings handler for delegation
     * 
     * @param \UpstateInternational\LGL\Admin\SettingsHandler $handler
     */
    public function setSettingsHandler($handler): void {
        $this->settingsHandler = $handler;
        // Helper::getInstance()->debug('🔗 ApiSettings: SettingsHandler injected successfully! Handler class: ' . get_class($handler));
    }
    
    /**
     * Set modern settings manager for delegation
     * 
     * @param \UpstateInternational\LGL\Admin\SettingsManager $manager
     */
    public function setSettingsManager($manager): void {
        $this->settingsManager = $manager;
    }
    
    /**
     * Lazy-load SettingsManager if not already set
     * 
     * @return void
     */
    private function ensureSettingsManager(): void {
        if ($this->settingsManager === null && function_exists('lgl_get_container')) {
            try {
                $container = lgl_get_container();
                if ($container->has('admin.settings_manager')) {
                    $this->settingsManager = $container->get('admin.settings_manager');
                }
            } catch (\Exception $e) {
                // Settings manager not available yet, will use fallback
            }
        }
    }
    
    /**
     * Initialize settings
     * 
     * Settings now managed entirely by SettingsManager via WordPress options.
     * Carbon Fields dependency removed.
     */
    private function initializeSettings(): void {
        // Settings now managed by SettingsManager
        // No initialization needed here
    }
    
    /**
     * Get API URL
     */
    public function getApiUrl(): string {
        $url = $this->getSetting('lgl_api_url');
        return $url ? rtrim($url, '/') : '';
    }
    
    /**
     * Get API Key
     */
    public function getApiKey(): string {
        return $this->getSetting('lgl_api_key') ?: '';
    }
    
    /**
     * Get membership levels
     */
    public function getMembershipLevels(): array {
        Helper::getInstance()->debug('🔍 ApiSettings::getMembershipLevels() called');
        Helper::getInstance()->debug('🔍 ApiSettings: settingsHandler is ' . ($this->settingsHandler ? 'SET' : 'NULL'));
        $levels = $this->getSetting('lgl_membership_levels');
        Helper::getInstance()->debug('🔍 ApiSettings::getMembershipLevels() called, raw result: ' . print_r($levels, true));
        $result = is_array($levels) ? $levels : [];
        Helper::getInstance()->debug('🔍 ApiSettings::getMembershipLevels() returning: ' . print_r($result, true));
        return $result;
    }
    
    /**
     * Get membership level by slug
     */
    public function getMembershipLevel(string $slug): ?array {
        $levels = $this->getMembershipLevels();
        
        foreach ($levels as $level) {
            if (isset($level['level_slug']) && $level['level_slug'] === $slug) {
                return $level;
            }
        }
        
        return null;
    }
    
    /**
     * Get LGL membership level ID by slug
     */
    public function getLglMembershipLevelId(string $slug): ?int {
        $level = $this->getMembershipLevel($slug);
        
        if ($level && isset($level['lgl_membership_level_id'])) {
            return (int) $level['lgl_membership_level_id'];
        }
        
        return null;
    }
    
    /**
     * Get membership level by LGL ID
     */
    public function getMembershipLevelByLglId(int $lgl_id): ?array {
        $levels = $this->getMembershipLevels();
        
        foreach ($levels as $level) {
            if (isset($level['lgl_membership_level_id']) && (int) $level['lgl_membership_level_id'] === $lgl_id) {
                return $level;
            }
        }
        
        return null;
    }
    
    /**
     * Check if debug mode is enabled
     */
    public function isDebugMode(): bool {
        return (bool) $this->getSetting('lgl_debug_mode');
    }
    
    /**
     * Check if test mode is enabled
     */
    public function isTestMode(): bool {
        return (bool) $this->getSetting('lgl_test_mode');
    }
    
    /**
     * Get setting value with caching
     * 
     * @deprecated 2.1.0 Use SettingsManager::get() instead
     */
    public function getSetting(string $setting_name, $default = null) {
        // Check cache first
        if (isset($this->settingsCache[$setting_name])) {
            return $this->settingsCache[$setting_name];
        }
        
        $value = null;
        
        // 1. Try SettingsManager first (preferred) - lazy load if needed
        $this->ensureSettingsManager();
        if ($this->settingsManager) {
            $modern_key = $this->mapToModernKey($setting_name);
            $value = $this->settingsManager->get($modern_key, null);
            if ($value !== null) {
                $this->settingsCache[$setting_name] = $value;
                return $value;
            }
        }
        
        // 2. Try modern settings handler (legacy bridge)
        if ($this->settingsHandler) {
            $modern_key = $this->mapToModernKey($setting_name);
            $settings = $this->settingsHandler->getSettings();
            $value = $settings[$modern_key] ?? null;
            if ($value !== null) {
                $this->settingsCache[$setting_name] = $value;
                return $value;
            }
        }
        
        // 3. Final fallback to WordPress options
        $value = get_option("_$setting_name", $default);
        
        // Cache the value
        $this->settingsCache[$setting_name] = $value !== null ? $value : $default;
        
        return $this->settingsCache[$setting_name];
    }
    
    /**
     * Map legacy Carbon Fields keys to modern SettingsHandler keys
     */
    private function mapToModernKey(string $legacy_key): string {
        $mapping = [
            'lgl_api_key' => 'api_key',
            'lgl_api_url' => 'api_url', 
            'lgl_membership_levels' => 'membership_levels',
            'lgl_debug_mode' => 'debug_mode',
            'lgl_test_mode' => 'test_mode'
        ];
        
        return $mapping[$legacy_key] ?? $legacy_key;
    }
    
    /**
     * Get legacy field name mapping
     */
    private function getLegacyFieldName(string $setting_name): ?string {
        $legacy_mapping = [
            'lgl_api_key' => 'api_key',
            'lgl_api_url' => 'constituents_uri', // Legacy used this for base URL
            'lgl_membership_levels' => 'membership_levels',
            'lgl_debug_mode' => 'debug_mode',
            'lgl_test_mode' => 'test_mode'
        ];
        
        return $legacy_mapping[$setting_name] ?? null;
    }
    
    /**
     * Update setting value
     */
    public function updateSetting(string $setting_name, $value): bool {
        // Update cache
        $this->settingsCache[$setting_name] = $value;
        
        // Update in database
        if (function_exists('carbon_set_theme_option')) {
            carbon_set_theme_option($setting_name, $value);
            return true;
        } else {
            return update_option("_$setting_name", $value);
        }
    }
    
    /**
     * Validate API configuration
     */
    public function validateApiConfig(): array {
        $errors = [];
        
        $api_url = $this->getApiUrl();
        $api_key = $this->getApiKey();
        
        if (empty($api_url)) {
            $errors[] = 'API URL is required';
        } elseif (!filter_var($api_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'API URL must be a valid URL';
        }
        
        if (empty($api_key)) {
            $errors[] = 'API Key is required';
        } elseif (strlen($api_key) < 10) {
            $errors[] = 'API Key appears to be too short';
        }
        
        return $errors;
    }
    
    /**
     * Get all settings as array
     */
    public function getAllSettings(): array {
        return [
            'api_url' => $this->getApiUrl(),
            'api_key' => $this->getApiKey(),
            'membership_levels' => $this->getMembershipLevels(),
            'debug_mode' => $this->isDebugMode(),
            'test_mode' => $this->isTestMode(),
            'config_valid' => empty($this->validateApiConfig())
        ];
    }
    
    /**
     * Clear settings cache
     */
    public function clearCache(): void {
        $this->settingsCache = [];
        Helper::getInstance()->debug('LGL API Settings: Cache cleared');
    }
    
    /**
     * Debug output with conditional display
     */
    public function debug(string $message, $data = null): void {
        if (!$this->isDebugMode()) {
            return;
        }
        
        $output = '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 3px;">';
        $output .= '<strong style="color: #856404;">LGL Debug:</strong> ' . esc_html($message);
        
        if ($data !== null) {
            $output .= '<pre style="margin-top: 10px; background: #f8f9fa; padding: 10px; overflow-x: auto;">';
            $output .= esc_html(print_r($data, true));
            $output .= '</pre>';
        }
        
        $output .= '</div>';
        
        echo $output;
        
        // Also log to error log
        $log_message = $message;
        if ($data !== null) {
            $log_message .= ' ' . print_r($data, true);
        }
        
        Helper::getInstance()->debug('LGL API Settings Debug: ' . $log_message);
    }
    
    /**
     * Export settings for backup
     */
    public function exportSettings(): array {
        $settings = $this->getAllSettings();
        
        // Remove sensitive data from export
        unset($settings['api_key']);
        
        return [
            'version' => '2.0.0',
            'exported_at' => current_time('mysql'),
            'settings' => $settings
        ];
    }
    
    /**
     * Get plugin version compatibility
     */
    public function getPluginVersion(): string {
        return defined('LGL_PLUGIN_VERSION') ? LGL_PLUGIN_VERSION : '2.0.0';
    }
}

// Maintain backward compatibility
if (!function_exists('lgl_api_settings')) {
    function lgl_api_settings(): ApiSettings {
        return ApiSettings::getInstance();
    }
}
