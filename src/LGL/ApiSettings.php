<?php
/**
 * LGL API Settings Manager
 * 
 * Manages plugin settings, configuration, and admin interface.
 * Handles Carbon Fields integration for settings pages.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

use Carbon_Fields\Container;
use Carbon_Fields\Field;

/**
 * API Settings Class
 * 
 * Manages LGL API configuration and settings pages
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
     * Get instance
     * 
     * @return ApiSettings
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
    }
    
    /**
     * Set modern settings handler for delegation
     * 
     * @param \UpstateInternational\LGL\Admin\SettingsHandler $handler
     */
    public function setSettingsHandler($handler): void {
        $this->settingsHandler = $handler;
        error_log('🔗 ApiSettings: SettingsHandler injected successfully! Handler class: ' . get_class($handler));
    }
    
    /**
     * Initialize settings
     */
    private function initializeSettings(): void {
        // RE-ENABLED: Carbon Fields needed to access existing data, but settings pages disabled
        // Initialize Carbon Fields if available
        if (class_exists('Carbon_Fields\\Container')) {
            // DON'T register settings pages (they're disabled)
            // add_action('carbon_fields_register_fields', [$this, 'registerSettingsFields']);
            add_action('after_setup_theme', [$this, 'initializeCarbonFields']);
        } else {
            add_action('admin_notices', [$this, 'showCarbonFieldsNotice']);
        }
        
        //  error_log('LGL API Settings: Initialized successfully (Carbon Fields enabled for data access, UI disabled)');
    }
    
    /**
     * Initialize Carbon Fields
     */
    public function initializeCarbonFields(): void {
        if (function_exists('carbon_fields_boot')) {
            \Carbon_Fields\Carbon_Fields::boot();
        }
    }
    
    /**
     * Show notice if Carbon Fields is not available
     */
    public function showCarbonFieldsNotice(): void {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-warning"><p><strong>LGL Plugin:</strong> Carbon Fields is required for the settings page. Please install the Carbon Fields plugin.</p></div>';
        }
    }
    
    /**
     * Register settings fields
     */
    public function registerSettingsFields(): void {
        try {
            Container::make('theme_options', 'LGL API Settings')
                ->set_page_parent('options-general.php')
                ->set_page_menu_title('LGL API')
                ->set_page_menu_position(30)
                ->add_fields([
                    Field::make('html', 'lgl_api_instructions')
                        ->set_html($this->getInstructionsHtml()),
                    
                    Field::make('text', 'lgl_api_url', 'API Base URL')
                        ->set_help_text('Your Little Green Light API base URL (e.g., https://api.littlegreenlight.com)')
                        ->set_attribute('placeholder', 'https://api.littlegreenlight.com')
                        ->set_required(true),
                    
                    Field::make('text', 'lgl_api_key', 'API Key')
                        ->set_help_text('Your Little Green Light API key')
                        ->set_attribute('type', 'password')
                        ->set_required(true),
                    
                    Field::make('separator', 'lgl_membership_separator', 'Membership Settings'),
                    
                    Field::make('complex', 'lgl_membership_levels', 'Membership Levels')
                        ->set_help_text('Configure your membership levels and their corresponding LGL membership level IDs for payment attribution')
                        ->add_fields([
                            Field::make('text', 'level_name', 'Level Name')
                                ->set_help_text('Display name for this membership level')
                                ->set_required(true),
                            
                            Field::make('text', 'level_slug', 'Level Slug')
                                ->set_help_text('Unique identifier for this level (lowercase, no spaces)')
                                ->set_required(true),
                            
                            Field::make('text', 'lgl_membership_level_id', 'LGL Membership Level ID')
                                ->set_help_text('The membership level ID from Little Green Light (used for payment attribution)')
                                ->set_attribute('type', 'number')
                                ->set_required(true),
                            
                            Field::make('text', 'price', 'Price')
                                ->set_help_text('Membership price (numbers only)')
                                ->set_attribute('type', 'number')
                                ->set_attribute('step', '0.01'),
                        ])
                        ->set_header_template('<%- level_name %> (ID: <%- lgl_membership_level_id %>) - $<%- price %>'),
                    
                    Field::make('separator', 'lgl_debug_separator', 'Debug Settings'),
                    
                    Field::make('checkbox', 'lgl_debug_mode', 'Enable Debug Mode')
                        ->set_help_text('Enable detailed logging for troubleshooting'),
                    
                    Field::make('checkbox', 'lgl_test_mode', 'Enable Test Mode')
                        ->set_help_text('Use test API endpoints (if available)'),
                ]);
                
            error_log('LGL API Settings: Carbon Fields registered successfully');
            
        } catch (\Exception $e) {
            error_log('LGL API Settings Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get instructions HTML
     */
    private function getInstructionsHtml(): string {
        return '
            <div style="background: #f1f1f1; padding: 20px; border-left: 4px solid #0073aa; margin: 10px 0;">
                <h3 style="margin-top: 0;">🔗 LGL API Configuration</h3>
                <p><strong>To configure your Little Green Light API connection:</strong></p>
                <ol>
                    <li>Log in to your Little Green Light account</li>
                    <li>Navigate to <strong>Settings → API</strong></li>
                    <li>Generate or copy your API key</li>
                    <li>Enter your API URL and key below</li>
                    <li><strong>For Membership Levels:</strong> Find the membership level IDs in LGL under <strong>Settings → Membership Levels</strong></li>
                </ol>
                <p><em>💡 <strong>Tip:</strong> The LGL Membership Level ID is crucial for payment attribution - it tells LGL which membership level to assign when processing payments.</em></p>
                <p><em>🔧 <strong>Debug:</strong> Enable debug mode below to see detailed logging and test your API connection.</em></p>
            </div>
        ';
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
        error_log('🔍 ApiSettings::getMembershipLevels() called');
        error_log('🔍 ApiSettings: settingsHandler is ' . ($this->settingsHandler ? 'SET' : 'NULL'));
        $levels = $this->getSetting('lgl_membership_levels');
        error_log('🔍 ApiSettings::getMembershipLevels() called, raw result: ' . print_r($levels, true));
        $result = is_array($levels) ? $levels : [];
        error_log('🔍 ApiSettings::getMembershipLevels() returning: ' . print_r($result, true));
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
     */
    public function getSetting(string $setting_name, $default = null) {
        // Check cache first
        if (isset($this->settingsCache[$setting_name])) {
            return $this->settingsCache[$setting_name];
        }
        
        $value = null;
        
        // Delegate to modern settings handler first (new native WordPress options)
        if ($this->settingsHandler) {
            $modern_key = $this->mapToModernKey($setting_name);
            $value = $this->settingsHandler->getSetting($modern_key, null);
            error_log("LGL Settings: Using modern handler for '$setting_name' -> '$modern_key', got: " . print_r($value, true));
        } else {
            error_log("LGL Settings: No modern settings handler available for '$setting_name' - settingsHandler is NULL");
        }
        
        // Fallback to Carbon Fields if modern handler didn't have the value
        if ($value === null && function_exists('carbon_get_theme_option')) {
            $value = carbon_get_theme_option($setting_name);
            
            // If not found, try legacy field names
            if (empty($value)) {
                $legacy_name = $this->getLegacyFieldName($setting_name);
                if ($legacy_name) {
                    $value = carbon_get_theme_option($legacy_name);
                    error_log("LGL Settings: Using legacy field name '$legacy_name' for '$setting_name'");
                }
            }
        }
        
        // Final fallback to WordPress options
        if ($value === null) {
            $value = get_option("_$setting_name", $default);
        }
        
        // Cache the value
        $this->settingsCache[$setting_name] = $value;
        
        return $value !== null ? $value : $default;
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
        error_log('LGL API Settings: Cache cleared');
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
        
        error_log('LGL API Settings Debug: ' . $log_message);
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
