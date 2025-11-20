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
        // Apply sanitization first
        $sanitized = $this->sanitizeSettings($settings);
        
        // Validate
        $validation = $this->validate($sanitized);
        if (!$validation['valid']) {
            $this->helper->debug('SettingsManager: Validation failed', $validation['errors']);
            return false;
        }
        
        // Get current settings
        $current = $this->getAll();
        
        // Debug: Log what we're merging
        $this->helper->debug('SettingsManager: Before merge', [
            'current_keys' => array_keys($current),
            'sanitized' => $sanitized
        ]);
        
        // Merge with updates
        $updated = array_merge($current, $sanitized);
        
        // Debug: Log what we're about to save
        $serialized = maybe_serialize($updated);
        $data_size = strlen($serialized);
        
        $this->helper->debug('SettingsManager: About to save', [
            'option_name' => self::OPTION_NAME,
            'keys_to_update' => array_keys($sanitized),
            'updated_values' => $sanitized,
            'total_keys' => count($updated),
            'serialized_size' => $data_size . ' bytes'
        ]);
        
        // Check if data is too large for autoload
        if ($data_size > 1048576) { // 1MB
            $this->helper->debug('SettingsManager: WARNING - Data size exceeds 1MB, this may cause issues');
        }
        
        // Save to database
        // Note: update_option returns false if the value hasn't changed, so we need to check if it actually failed
        $result = update_option(self::OPTION_NAME, $updated, 'no'); // Force no autoload for large data
        
        // Debug: Log update_option result
        $this->helper->debug('SettingsManager: update_option result', [
            'result' => $result,
            'option_name' => self::OPTION_NAME
        ]);
        
        // Always clear cache and consider it successful if the data matches what we wanted to save
        $this->cacheManager->delete(self::CACHE_KEY);
        $this->settings = null; // Clear memory cache
        
        // Verify the save by reading back (bypass cache)
        global $wpdb;
        $verify = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM $wpdb->options WHERE option_name = %s",
            self::OPTION_NAME
        ));
        $verify = maybe_unserialize($verify);
        
        // Debug: Log what we read back
        $this->helper->debug('SettingsManager: Read back from DB', [
            'has_data' => !empty($verify),
            'is_array' => is_array($verify),
            'keys_count' => is_array($verify) ? count($verify) : 0,
            'verify_type' => gettype($verify)
        ]);
        
        // Ensure verify is an array
        if (!is_array($verify)) {
            $verify = [];
            $this->helper->debug('SettingsManager: Verify was not an array, reset to empty array');
        }
        
        $success = true;
        
        foreach ($sanitized as $key => $value) {
            if (!isset($verify[$key]) || $verify[$key] !== $value) {
                $success = false;
                $this->helper->debug('SettingsManager: Setting verification failed', [
                    'key' => $key,
                    'expected' => $value,
                    'expected_type' => gettype($value),
                    'actual' => $verify[$key] ?? 'not set',
                    'actual_type' => isset($verify[$key]) ? gettype($verify[$key]) : 'N/A',
                    'verify_has_key' => array_key_exists($key, $verify)
                ]);
            }
        }
        
        if ($success) {
            $this->helper->debug('SettingsManager: Settings updated successfully', [
                'updated_keys' => array_keys($sanitized),
                'update_option_result' => $result
            ]);
        }
        
        return $success;
    }
    
    /**
     * Apply sanitization to settings based on schema
     * 
     * @param array $settings Settings to sanitize
     * @return array Sanitized settings
     */
    private function sanitizeSettings(array $settings): array {
        $schema = $this->getSchema();
        $sanitized = [];
        
        foreach ($settings as $key => $value) {
            if (!isset($schema[$key])) {
                // Unknown setting, skip
                continue;
            }
            
            $field = $schema[$key];
            
            // Apply sanitization callback if defined
            if (isset($field['sanitize'])) {
                $callback = $field['sanitize'];
                
                if (is_callable($callback)) {
                    // Custom callback
                    $sanitized[$key] = $callback($value);
                } elseif (is_string($callback) && function_exists($callback)) {
                    // Built-in PHP function
                    $sanitized[$key] = $callback($value);
                } else {
                    // No valid sanitization, use raw value
                    $sanitized[$key] = $value;
                }
            } else {
                // No sanitization defined, use raw value
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
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
     * Import membership levels from LGL API with pagination support
     * 
     * @return array ['success' => bool, 'levels' => array, 'message' => string]
     */
    public function importMembershipLevels(): array {
        try {
            $levels = [];
            $page = 1;
            $per_page = 100; // Max items per page
            $has_more = true;
            
            while ($has_more) {
                $response = $this->connection->makeRequest('membership_levels', 'GET', [
                    'page' => $page,
                    'per_page' => $per_page
                ], false);
                
                $this->helper->debug('SettingsManager: Import membership levels - Page ' . $page, [
                    'success' => $response['success'] ?? false,
                    'has_data' => isset($response['data']),
                    'page' => $page
                ]);
                
                if (!($response['success'] ?? false)) {
                    if ($page === 1) {
                        // First page failed - return error
                        return [
                            'success' => false,
                            'levels' => [],
                            'message' => 'Failed to fetch membership levels from LGL'
                        ];
                    }
                    // Subsequent page failed - stop pagination
                    break;
                }
                
                // LGL API returns data in nested structure: response['data']['items']
                $data = $response['data'] ?? [];
                $items = $data['items'] ?? $data; // Fallback to $data if 'items' key doesn't exist
                
                if (empty($items) || !is_array($items)) {
                    $has_more = false;
                    break;
                }
                
                foreach ($items as $level) {
                    // Skip if level doesn't have required fields
                    if (empty($level['name']) || empty($level['id'])) {
                        $this->helper->debug('SettingsManager: Skipping invalid level', $level);
                        continue;
                    }
                    
                    $levels[] = [
                        'level_name' => $level['name'] ?? '',
                        'level_slug' => sanitize_title($level['name'] ?? ''),
                        'lgl_membership_level_id' => (int) ($level['id'] ?? 0)
                    ];
                }
                
                // Check if there are more pages
                // LGL API typically returns pagination info in response['data']['pagination'] or similar
                $pagination = $data['pagination'] ?? [];
                $total_pages = $pagination['total_pages'] ?? null;
                $current_page = $pagination['current_page'] ?? $page;
                
                if ($total_pages !== null) {
                    $has_more = $current_page < $total_pages;
                } else {
                    // If no pagination info, check if we got fewer items than requested
                    $has_more = count($items) >= $per_page;
                }
                
                $page++;
                
                // Safety limit - prevent infinite loops
                if ($page > 100) {
                    $this->helper->debug('SettingsManager: Pagination safety limit reached');
                    break;
                }
            }
            
            $this->helper->debug('SettingsManager: Processed membership levels', [
                'count' => count($levels),
                'pages_fetched' => $page - 1
            ]);
            
            // Save imported levels
            $this->set('membership_levels', $levels);
            
            return [
                'success' => true,
                'levels' => $levels,
                'message' => sprintf('Successfully imported %d membership levels', count($levels))
            ];
        } catch (\Exception $e) {
            $this->helper->debug('SettingsManager: Import error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'levels' => [],
                'message' => 'Import error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Import events from LGL API with pagination support
     * 
     * @return array ['success' => bool, 'events' => array, 'message' => string]
     */
    public function importEvents(): array {
        try {
            $events = [];
            $page = 1;
            $per_page = 100; // Max items per page
            $has_more = true;
            
            while ($has_more) {
                $response = $this->connection->makeRequest('events', 'GET', [
                    'page' => $page,
                    'per_page' => $per_page
                ], false);
                
                $this->helper->debug('SettingsManager: Import events - Page ' . $page, [
                    'success' => $response['success'] ?? false,
                    'has_data' => isset($response['data']),
                    'page' => $page
                ]);
                
                if (!($response['success'] ?? false)) {
                    if ($page === 1) {
                        // First page failed - return error
                        return [
                            'success' => false,
                            'events' => [],
                            'message' => 'Failed to fetch events from LGL'
                        ];
                    }
                    // Subsequent page failed - stop pagination
                    break;
                }
                
                // LGL API returns data in nested structure: response['data']['items']
                $data = $response['data'] ?? [];
                $items = $data['items'] ?? $data;
                
                if (empty($items) || !is_array($items)) {
                    $has_more = false;
                    break;
                }
                
                foreach ($items as $event) {
                    // Skip if event doesn't have required fields
                    if (empty($event['name']) || empty($event['id'])) {
                        $this->helper->debug('SettingsManager: Skipping invalid event', $event);
                        continue;
                    }
                    
                    $events[] = [
                        'name' => $event['name'] ?? '',
                        'lgl_event_id' => (int) ($event['id'] ?? 0),
                        'description' => $event['description'] ?? '',
                        'date' => $event['date'] ?? '',
                        'end_date' => $event['end_date'] ?? '',
                        'financial_goal' => $event['financial_goal'] ?? null,
                        'projected_amount' => $event['projected_amount'] ?? null,
                        'code' => $event['code'] ?? ''
                    ];
                }
                
                // Check if there are more pages
                $pagination = $data['pagination'] ?? [];
                $total_pages = $pagination['total_pages'] ?? null;
                $current_page = $pagination['current_page'] ?? $page;
                
                if ($total_pages !== null) {
                    $has_more = $current_page < $total_pages;
                } else {
                    // If no pagination info, check if we got fewer items than requested
                    $has_more = count($items) >= $per_page;
                }
                
                $page++;
                
                // Safety limit - prevent infinite loops
                if ($page > 100) {
                    $this->helper->debug('SettingsManager: Pagination safety limit reached');
                    break;
                }
            }
            
            $this->helper->debug('SettingsManager: Processed events', [
                'count' => count($events),
                'pages_fetched' => $page - 1
            ]);
            
            // Save imported events
            $this->set('lgl_events', $events);
            
            return [
                'success' => true,
                'events' => $events,
                'message' => sprintf('Successfully imported %d events', count($events))
            ];
        } catch (\Exception $e) {
            $this->helper->debug('SettingsManager: Import events error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'events' => [],
                'message' => 'Import error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Import funds from LGL API with pagination support
     * 
     * @return array ['success' => bool, 'funds' => array, 'message' => string]
     */
    public function importFunds(): array {
        try {
            $funds = [];
            $page = 1;
            $per_page = 100; // Max items per page
            $has_more = true;
            
            while ($has_more) {
                $response = $this->connection->makeRequest('funds', 'GET', [
                    'page' => $page,
                    'per_page' => $per_page
                ], false);
                
                $this->helper->debug('SettingsManager: Import funds - Page ' . $page, [
                    'success' => $response['success'] ?? false,
                    'has_data' => isset($response['data']),
                    'page' => $page
                ]);
                
                if (!($response['success'] ?? false)) {
                    if ($page === 1) {
                        // First page failed - return error
                        return [
                            'success' => false,
                            'funds' => [],
                            'message' => 'Failed to fetch funds from LGL'
                        ];
                    }
                    // Subsequent page failed - stop pagination
                    break;
                }
                
                // LGL API returns data in nested structure: response['data']['items']
                $data = $response['data'] ?? [];
                $items = $data['items'] ?? $data;
                
                if (empty($items) || !is_array($items)) {
                    $has_more = false;
                    break;
                }
                
                foreach ($items as $fund) {
                    // Skip if fund doesn't have required fields
                    if (empty($fund['name']) || empty($fund['id'])) {
                        $this->helper->debug('SettingsManager: Skipping invalid fund', $fund);
                        continue;
                    }
                    
                    $funds[] = [
                        'name' => $fund['name'] ?? '',
                        'lgl_fund_id' => (int) ($fund['id'] ?? 0),
                        'description' => $fund['description'] ?? '',
                        'code' => $fund['code'] ?? '',
                        'start_date' => $fund['start_date'] ?? '',
                        'end_date' => $fund['end_date'] ?? '',
                        'financial_goal' => $fund['financial_goal'] ?? null
                    ];
                }
                
                // Check if there are more pages
                $pagination = $data['pagination'] ?? [];
                $total_pages = $pagination['total_pages'] ?? null;
                $current_page = $pagination['current_page'] ?? $page;
                
                if ($total_pages !== null) {
                    $has_more = $current_page < $total_pages;
                } else {
                    // If no pagination info, check if we got fewer items than requested
                    $has_more = count($items) >= $per_page;
                }
                
                $page++;
                
                // Safety limit - prevent infinite loops
                if ($page > 100) {
                    $this->helper->debug('SettingsManager: Pagination safety limit reached');
                    break;
                }
            }
            
            $this->helper->debug('SettingsManager: Processed funds', [
                'count' => count($funds),
                'pages_fetched' => $page - 1
            ]);
            
            // Save imported funds
            $this->set('lgl_funds', $funds);
            
            return [
                'success' => true,
                'funds' => $funds,
                'message' => sprintf('Successfully imported %d funds', count($funds))
            ];
        } catch (\Exception $e) {
            $this->helper->debug('SettingsManager: Import funds error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'funds' => [],
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
            // $this->helper->debug('SettingsManager: Migration already completed, skipping');
            return;
        }
        
        $this->helper->debug('SettingsManager: Starting Carbon Fields migration');
        
        // Try to load Carbon Fields data
        if (!function_exists('carbon_get_theme_option')) {
            // Carbon Fields not available, skip migration
            $this->helper->debug('SettingsManager: Carbon Fields not available, marking migration as complete');
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
        
        // Migrate email blocking settings (one-time)
        if (!get_option('lgl_email_blocking_settings_migrated')) {
            $force_blocking = get_option('lgl_force_email_blocking', false);
            $whitelist = get_option('lgl_email_whitelist', []);
            
            if ($force_blocking || !empty($whitelist)) {
                $current = get_option(self::OPTION_NAME, []);
                $current['force_email_blocking'] = (bool)$force_blocking;
                $current['email_whitelist'] = $whitelist;
                update_option(self::OPTION_NAME, $current);
                
                $this->helper->debug('SettingsManager: Migrated email blocking settings', [
                    'force_blocking' => (bool)$force_blocking,
                    'whitelist_count' => count($whitelist)
                ]);
            }
            
            update_option('lgl_email_blocking_settings_migrated', true);
        }
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
            
            // Email Blocking Configuration
            'force_email_blocking' => [
                'type' => 'boolean',
                'default' => false,
                'sanitize' => 'boolval',
                'label' => 'Force Block All Emails',
                'description' => 'Override environment detection and block all outgoing emails'
            ],
            'email_blocking_level' => [
                'type' => 'string',
                'default' => 'all',
                'validation' => ['in:all,woocommerce_allowed,cron_only'],
                'sanitize' => 'sanitize_text_field',
                'label' => 'Email Blocking Level',
                'description' => 'Level of email blocking: all, woocommerce_allowed, or cron_only'
            ],
            'email_whitelist' => [
                'type' => 'array',
                'default' => [],
                'sanitize' => function($val) { 
                    return array_map('sanitize_email', array_filter((array)$val, 'is_email')); 
                },
                'label' => 'Email Whitelist',
                'description' => 'Email addresses that are always allowed (admin email is automatic)'
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
                    ]
                ]
            ],
            
            // LGL Events (synced from API)
            'lgl_events' => [
                'type' => 'array',
                'default' => [],
                'sub_schema' => [
                    'name' => [
                        'type' => 'string',
                        'validation' => ['required'],
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'lgl_event_id' => [
                        'type' => 'integer',
                        'validation' => ['required', 'integer', 'min:1'],
                        'sanitize' => 'intval'
                    ],
                    'description' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_textarea_field'
                    ],
                    'date' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'end_date' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'financial_goal' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'projected_amount' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'code' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ]
                ]
            ],
            
            // LGL Funds (synced from API)
            'lgl_funds' => [
                'type' => 'array',
                'default' => [],
                'sub_schema' => [
                    'name' => [
                        'type' => 'string',
                        'validation' => ['required'],
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'lgl_fund_id' => [
                        'type' => 'integer',
                        'validation' => ['required', 'integer', 'min:1'],
                        'sanitize' => 'intval'
                    ],
                    'description' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_textarea_field'
                    ],
                    'code' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'start_date' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'end_date' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ],
                    'financial_goal' => [
                        'type' => 'string',
                        'sanitize' => 'sanitize_text_field'
                    ]
                ]
            ],
            
            // Mappings (optional, for future use)
            'fund_mappings' => [
                'type' => 'array',
                'default' => [],
                'sanitize' => function($val) { return array_map('intval', (array)$val); }
            ],
            
            // Consolidated Fund IDs (Post-Remediation)
            'fund_id_membership' => [
                'type' => 'integer',
                'default' => 2437,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Membership Fund ID',
                'description' => 'LGL fund ID for membership payments'
            ],
            'fund_id_language_classes' => [
                'type' => 'integer',
                'default' => 4132,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Language Classes Fund ID',
                'description' => 'LGL fund ID for language class registrations'
            ],
            'fund_id_events' => [
                'type' => 'integer',
                'default' => 4142,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Events Fund ID',
                'description' => 'LGL fund ID for event registrations'
            ],
            'fund_id_general' => [
                'type' => 'integer',
                'default' => 4127,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'General Fund ID',
                'description' => 'LGL fund ID for general donations'
            ],
            'fund_id_family_member_slots' => [
                'type' => 'integer',
                'default' => 4147,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Family Member Slots Fund ID',
                'description' => 'LGL fund ID for Family Member slot purchases (separate from membership fund)'
            ],
            
            // Cart Validation Settings
            'cart_validation' => [
                'type' => 'array',
                'default' => [
                    'require_membership_for_family_members' => true,
                    'max_family_members' => 6,
                    'allow_guest_family_member_purchase' => false,
                ],
                'sanitize' => function($val) {
                    return [
                        'require_membership_for_family_members' => isset($val['require_membership_for_family_members']) ? (bool)$val['require_membership_for_family_members'] : true,
                        'max_family_members' => isset($val['max_family_members']) ? max(1, (int)$val['max_family_members']) : 6,
                        'allow_guest_family_member_purchase' => isset($val['allow_guest_family_member_purchase']) ? (bool)$val['allow_guest_family_member_purchase'] : false,
                    ];
                },
                'label' => 'Cart Validation Rules',
                'description' => 'Business rules for cart validation'
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
            ],
            
            // Renewal Reminder Settings
            'renewal_reminders_enabled' => [
                'type' => 'boolean',
                'default' => true,
                'sanitize' => 'boolval',
                'label' => 'Enable Renewal Reminders',
                'description' => 'Send automated renewal reminders to members (only applies when WC Subscriptions is not active)'
            ],
            'renewal_grace_period_days' => [
                'type' => 'integer',
                'default' => 30,
                'validation' => ['integer', 'min:0', 'max:90'],
                'sanitize' => 'intval',
                'label' => 'Grace Period (Days)',
                'description' => 'Days after renewal date before membership is deactivated'
            ],
            'renewal_notification_intervals' => [
                'type' => 'array',
                'default' => [30, 14, 7, 0, -7, -30],
                'label' => 'Notification Intervals (Days)',
                'description' => 'Days before/after renewal to send reminders (negative = overdue)'
            ],
            
            // Email templates for 30 days before renewal
            'renewal_email_subject_30' => [
                'type' => 'string',
                'default' => '{first_name}, Your Upstate International Membership Renewal is Coming!',
                'sanitize' => 'sanitize_text_field',
                'label' => '30 Days Before - Subject'
            ],
            'renewal_email_content_30' => [
                'type' => 'text',
                'default' => '<h1>One more month!</h1><h2>Your Upstate International Membership renewal date is in 30 days.</h2><p>Please login to your <a href="' . get_site_url() . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online.</p><p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p><ul><li><a href="tel:+18646312188">864-631-2188</a></li><li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li></ul>',
                'sanitize' => 'wp_kses_post',
                'label' => '30 Days Before - Content'
            ],
            
            // Email templates for 14 days before renewal
            'renewal_email_subject_14' => [
                'type' => 'string',
                'default' => '{first_name}, Your Upstate International Membership Renewal is Coming!',
                'sanitize' => 'sanitize_text_field',
                'label' => '14 Days Before - Subject'
            ],
            'renewal_email_content_14' => [
                'type' => 'text',
                'default' => '<h1>Two more weeks!</h1><h2>Your Upstate International Membership renewal date is in 14 days.</h2><p>Please login to your <a href="' . get_site_url() . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online.</p><p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p><ul><li><a href="tel:+18646312188">864-631-2188</a></li><li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li></ul>',
                'sanitize' => 'wp_kses_post',
                'label' => '14 Days Before - Content'
            ],
            
            // Email templates for 7 days before renewal
            'renewal_email_subject_7' => [
                'type' => 'string',
                'default' => '{first_name}, Your Upstate International Membership Renewal is Coming!',
                'sanitize' => 'sanitize_text_field',
                'label' => '7 Days Before - Subject'
            ],
            'renewal_email_content_7' => [
                'type' => 'text',
                'default' => '<h1>One more week!</h1><h2>Your Upstate International Membership renewal date is in 7 days.</h2><p>Please login to your <a href="' . get_site_url() . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p><p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p><p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p><ul><li><a href="tel:+18646312188">864-631-2188</a></li><li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li></ul>',
                'sanitize' => 'wp_kses_post',
                'label' => '7 Days Before - Content'
            ],
            
            // Email templates for renewal day (0 days)
            'renewal_email_subject_0' => [
                'type' => 'string',
                'default' => '{first_name}, Your Upstate International Membership Renewal Date is Today!',
                'sanitize' => 'sanitize_text_field',
                'label' => 'Renewal Day - Subject'
            ],
            'renewal_email_content_0' => [
                'type' => 'text',
                'default' => '<h1>Today is the day!</h1><h2>Your Upstate International Membership renewal date is today.</h2><p>Please login to your <a href="' . get_site_url() . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p><p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p><p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p><ul><li><a href="tel:+18646312188">864-631-2188</a></li><li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li></ul>',
                'sanitize' => 'wp_kses_post',
                'label' => 'Renewal Day - Content'
            ],
            
            // Email templates for 7 days overdue (-7)
            'renewal_email_subject_-7' => [
                'type' => 'string',
                'default' => '{first_name}, Your Upstate International Membership Renewal Date has passed!',
                'sanitize' => 'sanitize_text_field',
                'label' => '7 Days Overdue - Subject'
            ],
            'renewal_email_content_-7' => [
                'type' => 'text',
                'default' => '<h1>Please renew your membership - it means the World to UI!</h1><h2>Your membership renewal date has passed.</h2><p>Please login to your <a href="' . get_site_url() . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p><p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p><p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p><ul><li><a href="tel:+18646312188">864-631-2188</a></li><li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li></ul>',
                'sanitize' => 'wp_kses_post',
                'label' => '7 Days Overdue - Content'
            ],
            
            // Email templates for 30 days overdue - inactive (-30)
            'renewal_email_subject_-30' => [
                'type' => 'string',
                'default' => '{first_name}, Your Upstate International Membership is now INACTIVE',
                'sanitize' => 'sanitize_text_field',
                'label' => '30 Days Overdue (Inactive) - Subject'
            ],
            'renewal_email_content_-30' => [
                'type' => 'text',
                'default' => '<h1>There\'s an issue with your membership subscription.</h1><h2>Your membership renewal date has passed and your one month grace period to renew your membership has expired.</h2><p><b>Your membership account has been marked as inactive.</b></p><p>If your membership plan includes family members, their accounts have also been marked as inactive.</p><p>After a 60 day period of inactivity, all user data for your account and family members\' accounts will be permanently removed from the Upstate International website.</p><h3>To reactivate your account</h3><p>Please follow the following steps:</p><ol><li>Reset your account password using the <a href="' . get_site_url() . '/my-account/lost-password">Login & Reset Password form</a>.</li><li>Make a new password and login into your account.</li><li>Add a Membership Level to your cart & complete your online checkout</li></ol><p>If you need to make changes to your membership, please feel free to stop by the office or contact us at:</p><ul><li><a href="tel:+18646312188">864-631-2188</a></li><li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li></ul>',
                'sanitize' => 'wp_kses_post',
                'label' => '30 Days Overdue (Inactive) - Content'
            ],
            
            // Order Email Templates
            'order_email_template_membership_new_subject' => [
                'type' => 'string',
                'default' => '',
                'sanitize' => 'sanitize_text_field',
                'label' => 'New Membership - Subject'
            ],
            'order_email_template_membership_new_content' => [
                'type' => 'text',
                'default' => function() {
                    $file = LGL_PLUGIN_DIR . 'form-emails/membership-confirmation.html';
                    return file_exists($file) ? file_get_contents($file) : '';
                },
                'sanitize' => 'wp_kses_post',
                'label' => 'New Membership - Content'
            ],
            'order_email_template_membership_renewal_subject' => [
                'type' => 'string',
                'default' => '',
                'sanitize' => 'sanitize_text_field',
                'label' => 'Membership Renewal - Subject'
            ],
            'order_email_template_membership_renewal_content' => [
                'type' => 'text',
                'default' => function() {
                    $file = LGL_PLUGIN_DIR . 'form-emails/membership-renewal.html';
                    return file_exists($file) ? file_get_contents($file) : '';
                },
                'sanitize' => 'wp_kses_post',
                'label' => 'Membership Renewal - Content'
            ],
            'order_email_template_language_class_subject' => [
                'type' => 'string',
                'default' => '',
                'sanitize' => 'sanitize_text_field',
                'label' => 'Language Class - Subject'
            ],
            'order_email_template_language_class_content' => [
                'type' => 'text',
                'default' => function() {
                    $file = LGL_PLUGIN_DIR . 'form-emails/language-class-registration.html';
                    return file_exists($file) ? file_get_contents($file) : '';
                },
                'sanitize' => 'wp_kses_post',
                'label' => 'Language Class - Content'
            ],
            'order_email_template_event_with_lunch_subject' => [
                'type' => 'string',
                'default' => '',
                'sanitize' => 'sanitize_text_field',
                'label' => 'Event (With Lunch) - Subject'
            ],
            'order_email_template_event_with_lunch_content' => [
                'type' => 'text',
                'default' => function() {
                    $file = LGL_PLUGIN_DIR . 'form-emails/event-with-lunch.html';
                    return file_exists($file) ? file_get_contents($file) : '';
                },
                'sanitize' => 'wp_kses_post',
                'label' => 'Event (With Lunch) - Content'
            ],
            'order_email_template_event_no_lunch_subject' => [
                'type' => 'string',
                'default' => '',
                'sanitize' => 'sanitize_text_field',
                'label' => 'Event (No Lunch) - Subject'
            ],
            'order_email_template_event_no_lunch_content' => [
                'type' => 'text',
                'default' => function() {
                    $file = LGL_PLUGIN_DIR . 'form-emails/event-no-lunch.html';
                    return file_exists($file) ? file_get_contents($file) : '';
                },
                'sanitize' => 'wp_kses_post',
                'label' => 'Event (No Lunch) - Content'
            ],
            'order_email_template_general_subject' => [
                'type' => 'string',
                'default' => 'Thank you for your support of Upstate International!',
                'sanitize' => 'sanitize_text_field',
                'label' => 'General Orders - Subject'
            ],
            'order_email_template_general_content' => [
                'type' => 'text',
                'default' => function() {
                    $file = LGL_PLUGIN_DIR . 'form-emails/general-order-confirmation.html';
                    return file_exists($file) ? file_get_contents($file) : '';
                },
                'sanitize' => 'wp_kses_post',
                'label' => 'General Orders - Content'
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

