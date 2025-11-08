<?php
/**
 * Settings Manager
 * 
 * Unified settings management service for all LGL configuration.
 * Single source of truth for settings with validation, caching, and migration support.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\Core\CacheManager;

/**
 * SettingsManager Class
 * 
 * Manages all LGL settings with validation and caching
 */
class SettingsManager implements SettingsManagerInterface {
    
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
     * Cache manager service
     * 
     * @var CacheManager
     */
    private CacheManager $cacheManager;
    
    /**
     * Settings option name
     */
    const OPTION_NAME = 'lgl_integration_settings';
    
    /**
     * Cache key for settings
     */
    const CACHE_KEY = 'lgl_settings_cache';
    
    /**
     * Cache TTL (1 hour)
     */
    const CACHE_TTL = 3600;
    
    /**
     * Migration flag option name
     */
    const MIGRATION_FLAG = 'lgl_carbon_fields_migrated';
    
    /**
     * Cached schema
     * 
     * @var array|null
     */
    private ?array $schema = null;
    
    /**
     * Cached settings
     * 
     * @var array|null
     */
    private ?array $settings = null;
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param Connection $connection Connection service
     * @param CacheManager $cacheManager Cache manager service
     */
    public function __construct(Helper $helper, Connection $connection, CacheManager $cacheManager) {
        $this->helper = $helper;
        $this->connection = $connection;
        $this->cacheManager = $cacheManager;
    }
    
    /**
     * Get all settings with defaults applied
     * 
     * @return array Complete settings array with defaults
     */
    public function getAll(): array {
        if ($this->settings !== null) {
            return $this->settings;
        }
        
        // Run migration check on first load
        $this->migrateFromCarbonFields();
        
        $this->settings = $this->loadSettings();
        return $this->settings;
    }
    
    /**
     * Get a single setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value if setting not found
     * @return mixed Setting value or default
     */
    public function get(string $key, $default = null) {
        $settings = $this->getAll();
        return $settings[$key] ?? $default;
    }
    
    /**
     * Update multiple settings at once
     * 
     * @param array $settings Settings to update
     * @return bool True on success, false on failure
     */
    public function update(array $settings): bool {
        // Validate first
        $validation = $this->validate($settings);
        if (!$validation['valid']) {
            $this->helper->debug('SettingsManager: Validation failed', $validation['errors']);
            return false;
        }
        
        // Get current settings
        $current = $this->getAll();
        
        // Merge with updates
        $updated = array_merge($current, $settings);
        
        // Save to database
        $result = update_option(self::OPTION_NAME, $updated);
        
        if ($result) {
            // Clear cache
            $this->cacheManager->delete(self::CACHE_KEY);
            $this->settings = null; // Clear memory cache
            
            $this->helper->debug('SettingsManager: Settings updated successfully');
        }
        
        return $result;
    }
    
    /**
     * Update a single setting
     * 
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool True on success, false on failure
     */
    public function set(string $key, $value): bool {
        return $this->update([$key => $value]);
    }
    
    /**
     * Validate settings before save
     * 
     * @param array $settings Settings to validate
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate(array $settings): array {
        $errors = [];
        $schema = $this->getSchema();
        
        foreach ($settings as $key => $value) {
            if (!isset($schema[$key])) {
                continue; // Unknown setting, skip validation
            }
            
            $field = $schema[$key];
            $rules = $field['validation'] ?? [];
            
            foreach ($rules as $rule) {
                $result = $this->validateRule($rule, $value, $key);
                if (!$result['valid']) {
                    $errors[$key][] = $result['message'];
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Get settings schema with validation rules
     * 
     * @return array Complete schema definition
     */
    public function getSchema(): array {
        if ($this->schema !== null) {
            return $this->schema;
        }
        
        $this->schema = $this->getDefaultSchema();
        return $this->schema;
    }
    
    /**
     * Test API connection with current or provided credentials
     * 
     * @param string|null $apiUrl Optional API URL to test
     * @param string|null $apiKey Optional API key to test
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function testConnection(?string $apiUrl = null, ?string $apiKey = null): array {
        // Use provided credentials or get from settings
        if ($apiUrl === null || $apiKey === null) {
            $settings = $this->getAll();
            $apiUrl = $apiUrl ?? $settings['api_url'] ?? '';
            $apiKey = $apiKey ?? $settings['api_key'] ?? '';
        }
        
        if (empty($apiUrl) || empty($apiKey)) {
            return [
                'success' => false,
                'message' => 'API URL and API Key are required',
                'data' => []
            ];
        }
        
        try {
            // Test connection by making a simple API call
            $response = $this->connection->makeRequest('constituents', 'GET', ['limit' => 1], false);
            
            if ($response['success'] ?? false) {
                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'data' => $response
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $response['error'] ?? 'Connection failed',
                    'data' => $response
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Import membership levels from LGL API
     * 
     * @return array ['success' => bool, 'levels' => array, 'message' => string]
     */
    public function importMembershipLevels(): array {
        try {
            $response = $this->connection->makeRequest('membership_levels', 'GET', [], false);
            
            if (!($response['success'] ?? false)) {
                return [
                    'success' => false,
                    'levels' => [],
                    'message' => 'Failed to fetch membership levels from LGL'
                ];
            }
            
            $levels = [];
            $data = $response['data'] ?? [];
            
            foreach ($data as $level) {
                $levels[] = [
                    'level_name' => $level['name'] ?? '',
                    'level_slug' => sanitize_title($level['name'] ?? ''),
                    'lgl_membership_level_id' => $level['id'] ?? 0,
                    'price' => 0.00 // Price needs to be set manually
                ];
            }
            
            // Save imported levels
            $this->set('membership_levels', $levels);
            
            return [
                'success' => true,
                'levels' => $levels,
                'message' => sprintf('Successfully imported %d membership levels', count($levels))
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'levels' => [],
                'message' => 'Import error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Reset all settings to defaults
     * 
     * @return bool True on success, false on failure
     */
    public function reset(): bool {
        $schema = $this->getSchema();
        $defaults = [];
        
        foreach ($schema as $key => $field) {
            $default = $field['default'];
            $defaults[$key] = is_callable($default) ? $default() : $default;
        }
        
        $result = update_option(self::OPTION_NAME, $defaults);
        
        if ($result) {
            $this->cacheManager->delete(self::CACHE_KEY);
            $this->settings = null;
            $this->helper->debug('SettingsManager: Settings reset to defaults');
        }
        
        return $result;
    }
    
    /**
     * Export settings as JSON
     * 
     * @return string JSON encoded settings
     */
    public function export(): string {
        $settings = $this->getAll();
        return json_encode($settings, JSON_PRETTY_PRINT);
    }
    
    /**
     * Import settings from JSON
     * 
     * @param string $json JSON encoded settings
     * @return array ['success' => bool, 'message' => string, 'imported' => int]
     */
    public function import(string $json): array {
        $settings = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid JSON: ' . json_last_error_msg(),
                'imported' => 0
            ];
        }
        
        if (!is_array($settings)) {
            return [
                'success' => false,
                'message' => 'Invalid settings format',
                'imported' => 0
            ];
        }
        
        // Validate before importing
        $validation = $this->validate($settings);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => 'Validation failed: ' . implode(', ', array_keys($validation['errors'])),
                'imported' => 0
            ];
        }
        
        $result = $this->update($settings);
        
        return [
            'success' => $result,
            'message' => $result ? 'Settings imported successfully' : 'Import failed',
            'imported' => $result ? count($settings) : 0
        ];
    }
    
    /**
     * Load settings from cache or database
     * 
     * @return array Settings array
     */
    private function loadSettings(): array {
        // 1. Try cache
        $cached = $this->cacheManager->get(self::CACHE_KEY);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        
        // 2. Load from WP option
        $settings = get_option(self::OPTION_NAME, []);
        
        // 3. Apply defaults from schema
        $schema = $this->getSchema();
        foreach ($schema as $key => $field) {
            if (!isset($settings[$key])) {
                $default = $field['default'];
                $settings[$key] = is_callable($default) ? $default() : $default;
            }
        }
        
        // 4. Cache the result
        $this->cacheManager->set(self::CACHE_KEY, $settings, self::CACHE_TTL);
        
        return $settings;
    }
    
    /**
     * Migrate settings from Carbon Fields (one-time operation)
     * 
     * @return void
     */
    private function migrateFromCarbonFields(): void {
        // Check if migration already done
        if (get_option(self::MIGRATION_FLAG)) {
            return;
        }
        
        // Try to load Carbon Fields data
        if (!function_exists('carbon_get_theme_option')) {
            // Carbon Fields not available, skip migration
            update_option(self::MIGRATION_FLAG, true);
            return;
        }
        
        $migrated = [];
        $mapping = [
            'lgl_api_url' => 'api_url',
            'lgl_api_key' => 'api_key',
            'lgl_membership_levels' => 'membership_levels',
            'lgl_debug_mode' => 'debug_mode',
            'lgl_test_mode' => 'test_mode'
        ];
        
        foreach ($mapping as $carbon_key => $wp_key) {
            $value = carbon_get_theme_option($carbon_key);
            if ($value !== null && $value !== '') {
                $migrated[$wp_key] = $value;
            }
        }
        
        if (!empty($migrated)) {
            $current = get_option(self::OPTION_NAME, []);
            $merged = array_merge($current, $migrated);
            update_option(self::OPTION_NAME, $merged);
            
            $this->helper->debug('SettingsManager: Migrated settings from Carbon Fields', [
                'migrated_keys' => array_keys($migrated)
            ]);
        }
        
        // Mark migration as complete
        update_option(self::MIGRATION_FLAG, true);
    }
    
    /**
     * Get default schema definition
     * 
     * @return array Schema definition
     */
    private function getDefaultSchema(): array {
        return [
            // API Configuration
            'api_url' => [
                'type' => 'string',
                'default' => '',
                'validation' => ['required', 'url'],
                'sanitize' => 'sanitize_url'
            ],
            'api_key' => [
                'type' => 'string',
                'default' => '',
                'validation' => ['required', 'min:32'],
                'sanitize' => 'sanitize_text_field'
            ],
            
            // System Settings
            'debug_mode' => [
                'type' => 'boolean',
                'default' => false,
                'sanitize' => 'boolval'
            ],
            'test_mode' => [
                'type' => 'boolean',
                'default' => false,
                'sanitize' => 'boolval'
            ],
            'log_level' => [
                'type' => 'string',
                'default' => 'info',
                'validation' => ['in:error,warning,info,debug'],
                'sanitize' => 'sanitize_text_field'
            ],
            
            // Performance
            'cache_ttl' => [
                'type' => 'integer',
                'default' => 3600,
                'validation' => ['integer', 'min:0'],
                'sanitize' => 'intval'
            ],
            'api_request_limit' => [
                'type' => 'integer',
                'default' => 1000,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval'
            ],
            
            // Email
            'email_blocking_enabled' => [
                'type' => 'boolean',
                'default' => true,
                'sanitize' => 'boolval'
            ],
            'admin_notification_email' => [
                'type' => 'string',
                'default' => function() { return get_option('admin_email'); },
                'validation' => ['email'],
                'sanitize' => 'sanitize_email'
            ],
            'daily_summary_enabled' => [
                'type' => 'boolean',
                'default' => true,
                'sanitize' => 'boolval'
            ],
            
            // Membership Levels (complex field)
            'membership_levels' => [
                'type' => 'array',
                'default' => [],
                'sub_schema' => [
                    'level_name' => [
                        'type' => 'string',
                        'validation' => ['required'],
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'level_slug' => [
                        'type' => 'string',
                        'validation' => ['required'],
                        'sanitize' => 'sanitize_title'
                    ],
                    'lgl_membership_level_id' => [
                        'type' => 'integer',
                        'validation' => ['required', 'integer', 'min:1'],
                        'sanitize' => 'intval'
                    ],
                    'price' => [
                        'type' => 'float',
                        'validation' => ['numeric', 'min:0'],
                        'sanitize' => 'floatval'
                    ]
                ]
            ],
            
            // Mappings (optional, for future use)
            'fund_mappings' => [
                'type' => 'array',
                'default' => [],
                'sanitize' => function($val) { return array_map('intval', (array)$val); }
            ],
            'campaign_mappings' => [
                'type' => 'array',
                'default' => [],
                'sanitize' => function($val) { return array_map('intval', (array)$val); }
            ],
            'payment_types' => [
                'type' => 'array',
                'default' => [],
                'sanitize' => function($val) { return array_map('intval', (array)$val); }
            ],
            'relation_endpoints' => [
                'type' => 'array',
                'default' => [],
                'sanitize' => function($val) { return array_map('sanitize_url', (array)$val); }
            ]
        ];
    }
    
    /**
     * Validate a single rule
     * 
     * @param string $rule Validation rule
     * @param mixed $value Value to validate
     * @param string $key Field key
     * @return array ['valid' => bool, 'message' => string]
     */
    private function validateRule(string $rule, $value, string $key): array {
        // Handle parameterized rules (e.g., "min:32")
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $param = $parts[1] ?? null;
        
        switch ($ruleName) {
            case 'required':
                if (empty($value) && $value !== '0' && $value !== 0) {
                    return ['valid' => false, 'message' => "$key is required"];
                }
                break;
                
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    return ['valid' => false, 'message' => "$key must be a valid URL"];
                }
                break;
                
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ['valid' => false, 'message' => "$key must be a valid email"];
                }
                break;
                
            case 'integer':
                if (!is_numeric($value) || (int)$value != $value) {
                    return ['valid' => false, 'message' => "$key must be an integer"];
                }
                break;
                
            case 'numeric':
                if (!is_numeric($value)) {
                    return ['valid' => false, 'message' => "$key must be numeric"];
                }
                break;
                
            case 'min':
                if (is_string($value) && strlen($value) < (int)$param) {
                    return ['valid' => false, 'message' => "$key must be at least $param characters"];
                } elseif (is_numeric($value) && $value < (float)$param) {
                    return ['valid' => false, 'message' => "$key must be at least $param"];
                }
                break;
                
            case 'in':
                $allowed = explode(',', $param);
                if (!in_array($value, $allowed, true)) {
                    return ['valid' => false, 'message' => "$key must be one of: " . implode(', ', $allowed)];
                }
                break;
        }
        
        return ['valid' => true, 'message' => ''];
    }
}

