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
     * Flag to prevent recursion during settings loading
     * 
     * @var bool
     */
    private bool $isLoadingSettings = false;
    
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
        // CRITICAL: Always call loadSettings() to ensure fresh data
        // Do NOT use in-memory cache as it can cause stale data and memory issues
        // The transient cache in loadSettings() provides sufficient caching
        
        // Run migration checks on first load (only once per request)
        static $migrations_run = false;
        if (!$migrations_run) {
            $this->migrateFromCarbonFields();
            $this->migrateLegacyApiSettings();
            $migrations_run = true;
        }
        
        return $this->loadSettings();
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
            $this->helper->error('SettingsManager: Validation failed', $validation['errors']);
            return false;
        }
        
        // Get current settings
        $current = $this->getAll();
        
        // Merge with updates
        $updated = array_merge($current, $sanitized);
        
        // Check if data is too large for autoload
        $serialized = maybe_serialize($updated);
        $data_size = strlen($serialized);
        if ($data_size > 1048576) { // 1MB
            $this->helper->warning('SettingsManager: Data size exceeds 1MB, this may cause issues', [
                'size_bytes' => $data_size
            ]);
        }
        
        // Save to database
        // Note: update_option returns false if the value hasn't changed, so we need to check if it actually failed
        $result = update_option(self::OPTION_NAME, $updated, 'no'); // Force no autoload for large data
        
        // Always clear cache for BOTH environments to ensure correct cache is used
        // Clear both dev and live caches since environment might have changed
        $cache_prefix = 'lgl_cache_';
        delete_transient($cache_prefix . 'dev_' . self::CACHE_KEY);
        delete_transient($cache_prefix . 'live_' . self::CACHE_KEY);
        // Note: No in-memory cache to clear - we always load fresh from DB/transient
        
        // Verify the save by reading back (bypass cache)
        global $wpdb;
        $verify = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM $wpdb->options WHERE option_name = %s",
            self::OPTION_NAME
        ));
        $verify = maybe_unserialize($verify);
        
        // Ensure verify is an array
        if (!is_array($verify)) {
            $verify = [];
        }
        
        $success = true;
        
        foreach ($sanitized as $key => $value) {
            if (!isset($verify[$key]) || $verify[$key] !== $value) {
                $success = false;
                $this->helper->error('SettingsManager: Setting verification failed', [
                    'key' => $key,
                    'expected' => $value,
                    'actual' => $verify[$key] ?? 'not set'
                ]);
            }
        }
        
        if ($success) {
            $this->helper->info('SettingsManager: Settings updated successfully', [
                'updated_keys' => array_keys($sanitized)
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
                    // Built-in PHP function - handle empty strings specially for URLs
                    if ($callback === 'sanitize_url' && $value === '') {
                        // Preserve empty strings for URL fields
                        $sanitized[$key] = '';
                    } else {
                        $sanitized[$key] = $callback($value);
                    }
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
                    break;
                }
            }
            
            // Save imported levels
            $this->set('membership_levels', $levels);
            
            $this->helper->info('SettingsManager: Membership levels imported', [
                'count' => count($levels),
                'pages_fetched' => $page - 1
            ]);
            
            return [
                'success' => true,
                'levels' => $levels,
                'message' => sprintf('Successfully imported %d membership levels', count($levels))
            ];
        } catch (\Exception $e) {
            $this->helper->error('SettingsManager: Import membership levels error', [
                'error' => $e->getMessage()
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
                    break;
                }
            }
            
            // Save imported events
            $this->set('lgl_events', $events);
            
            $this->helper->info('SettingsManager: Events imported', [
                'count' => count($events),
                'pages_fetched' => $page - 1
            ]);
            
            return [
                'success' => true,
                'events' => $events,
                'message' => sprintf('Successfully imported %d events', count($events))
            ];
        } catch (\Exception $e) {
            $this->helper->error('SettingsManager: Import events error', [
                'error' => $e->getMessage()
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
                    break;
                }
            }
            
            // Save imported funds
            $this->set('lgl_funds', $funds);
            
            $this->helper->info('SettingsManager: Funds imported', [
                'count' => count($funds),
                'pages_fetched' => $page - 1
            ]);
            
            return [
                'success' => true,
                'funds' => $funds,
                'message' => sprintf('Successfully imported %d funds', count($funds))
            ];
        } catch (\Exception $e) {
            $this->helper->error('SettingsManager: Import funds error', [
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'funds' => [],
                'message' => 'Import error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Sync Fund and Campaign IDs from LGL API
     * 
     * Fetches current funds and campaigns from LGL and updates settings
     * with matching IDs based on expected names.
     * 
     * @return array Results with success status and matched IDs
     */
    public function syncFundAndCampaignIds(): array {
        $results = [
            'success' => false,
            'funds' => [],
            'campaigns' => [],
            'errors' => []
        ];
        
        try {
            // Clear cache for funds.json and campaigns.json endpoints before fetching
            $this->cacheManager->delete('api_request_' . md5('funds.json' . serialize(['limit' => 100, 'offset' => 0])));
            $this->cacheManager->delete('api_request_' . md5('campaigns.json' . serialize(['limit' => 100, 'offset' => 0])));
            
            // Expected mappings (name → setting key)
            // Note: These are the target names after remediation
            $expected_funds = [
                'Membership' => 'fund_id_membership',
                'Language Classes' => 'fund_id_language_classes',
                'Events' => 'fund_id_events',
                'General Donation' => 'fund_id_general',
                'Family Member Slots' => 'fund_id_family_member_slots',
            ];
            
            $expected_campaigns = [
                'Membership' => 'campaign_id_membership',
                'Language Programs' => 'campaign_id_language_classes',
                'Events' => 'campaign_id_events',
            ];
            
            // MEMORY FIX: Build normalized expected funds map once (outside loop)
            $expected_funds_normalized = [];
            foreach ($expected_funds as $expected_name => $setting_key) {
                $expected_funds_normalized[strtolower(trim($expected_name))] = [
                    'original_name' => $expected_name,
                    'setting_key' => $setting_key
                ];
            }
            
            // Also add common variations/aliases based on remediation CSV
            $expected_funds_normalized[strtolower('Unknown Language Class')] = [
                'original_name' => 'Language Classes',
                'setting_key' => 'fund_id_language_classes'
            ];
            $expected_funds_normalized[strtolower('Cultural Event')] = [
                'original_name' => 'Events',
                'setting_key' => 'fund_id_events'
            ];
            
            // Fallback: ID-based matching only if name matching failed
            $fund_id_mapping_fallback = [
                4147 => 'fund_id_family_member_slots', // Family Member Slots (if name doesn't match)
            ];
            
            // Fetch funds with pagination support (bypass cache)
            // MEMORY FIX: Process items incrementally instead of accumulating all in memory
            $limit = 100;
            $offset = 0;
            $has_more = true;
            $total_funds_processed = 0;
            $funds_found = false;
            
            while ($has_more) {
                // Bypass cache to ensure fresh data
                $funds_response = $this->connection->makeRequest('funds.json', 'GET', [
                    'limit' => $limit,
                    'offset' => $offset
                ], false);
                
                if (!($funds_response['success'] ?? false)) {
                    if ($offset === 0) {
                        $results['errors'][] = 'Failed to fetch funds from LGL API';
                        break;
                    }
                    break;
                }
                
                $data = $funds_response['data'] ?? [];
                $items = $data['items'] ?? [];
                
                if (empty($items) || !is_array($items)) {
                    $has_more = false;
                    break;
                }
                
                // MEMORY FIX: Process items immediately instead of accumulating
                foreach ($items as $fund) {
                    $fund_id = (int) ($fund['id'] ?? 0);
                    $name = trim($fund['name'] ?? '');
                    $name_lower = strtolower($name);
                    
                    // Try exact match first
                    if (isset($expected_funds[$name])) {
                        $setting_key = $expected_funds[$name];
                        if (!isset($results['funds'][$setting_key])) {
                            $results['funds'][$setting_key] = [
                                'id' => $fund_id,
                                'name' => $name
                            ];
                            $funds_found = true;
                        }
                    }
                    // Try case-insensitive match or alias
                    elseif (isset($expected_funds_normalized[$name_lower])) {
                        $mapping = $expected_funds_normalized[$name_lower];
                        $setting_key = $mapping['setting_key'];
                        if (!isset($results['funds'][$setting_key])) {
                            $results['funds'][$setting_key] = [
                                'id' => $fund_id,
                                'name' => $name
                            ];
                            $funds_found = true;
                        }
                    }
                    // Fallback: ID-based matching
                    elseif (isset($fund_id_mapping_fallback[$fund_id])) {
                        $setting_key = $fund_id_mapping_fallback[$fund_id];
                        if (!isset($results['funds'][$setting_key])) {
                            $results['funds'][$setting_key] = [
                                'id' => $fund_id,
                                'name' => $name
                            ];
                            $funds_found = true;
                        }
                    }
                }
                
                $total_funds_processed += count($items);
                
                // Get pagination info
                $items_count = $data['items_count'] ?? count($items);
                $total_items = $data['total_items'] ?? null;
                $next_item = $data['next_item'] ?? null;
                
                // Determine if there are more pages
                if ($total_items !== null) {
                    $has_more = $total_funds_processed < $total_items;
                } elseif ($next_item !== null) {
                    $has_more = $next_item < ($total_items ?? PHP_INT_MAX);
                } elseif (!empty($data['next_link'])) {
                    $has_more = true;
                } else {
                    $has_more = count($items) >= $limit;
                }
                
                // Update offset for next iteration
                $offset = $next_item ?? ($offset + $items_count);
                
                // Free memory after processing batch
                unset($items, $data, $funds_response);
                
                // Safety limit - prevent infinite loops (max 1000 items = 10 pages of 100)
                if ($offset >= 1000 || $total_funds_processed >= 1000) {
                    break;
                }
            }
            
            if (!$funds_found) {
                $results['errors'][] = 'Failed to fetch funds: No matching funds found';
            }
            
            // MEMORY FIX: Build normalized expected campaigns map once (outside loop)
            $expected_campaigns_normalized = [];
            foreach ($expected_campaigns as $expected_name => $setting_key) {
                $expected_campaigns_normalized[strtolower(trim($expected_name))] = [
                    'original_name' => $expected_name,
                    'setting_key' => $setting_key
                ];
            }
            
            // Add common variations/aliases
            $expected_campaigns_normalized[strtolower('Membership Fees')] = [
                'original_name' => 'Membership',
                'setting_key' => 'campaign_id_membership'
            ];
            $expected_campaigns_normalized[strtolower('Language Class')] = [
                'original_name' => 'Language Programs',
                'setting_key' => 'campaign_id_language_classes'
            ];
            $expected_campaigns_normalized[strtolower('WACU Programming')] = [
                'original_name' => 'Events',
                'setting_key' => 'campaign_id_events'
            ];
            
            // Fetch campaigns with pagination support (bypass cache)
            // MEMORY FIX: Process items incrementally instead of accumulating all in memory
            $limit = 100;
            $offset = 0;
            $has_more = true;
            $total_campaigns_processed = 0;
            $campaigns_found = false;
            
            while ($has_more) {
                // Bypass cache to ensure fresh data
                $campaigns_response = $this->connection->makeRequest('campaigns.json', 'GET', [
                    'limit' => $limit,
                    'offset' => $offset
                ], false);
                
                if (!($campaigns_response['success'] ?? false)) {
                    if ($offset === 0) {
                        $results['errors'][] = 'Failed to fetch campaigns from LGL API';
                        break;
                    }
                    break;
                }
                
                $data = $campaigns_response['data'] ?? [];
                $items = $data['items'] ?? [];
                
                if (empty($items) || !is_array($items)) {
                    $has_more = false;
                    break;
                }
                
                // MEMORY FIX: Process items immediately instead of accumulating
                foreach ($items as $campaign) {
                    $campaign_id = (int) ($campaign['id'] ?? 0);
                    $name = trim($campaign['name'] ?? '');
                    $name_lower = strtolower($name);
                    
                    // Try exact match first
                    if (isset($expected_campaigns[$name])) {
                        $setting_key = $expected_campaigns[$name];
                        if (!isset($results['campaigns'][$setting_key])) {
                            $results['campaigns'][$setting_key] = [
                                'id' => $campaign_id,
                                'name' => $name
                            ];
                            $campaigns_found = true;
                        }
                    }
                    // Try case-insensitive match or alias
                    elseif (isset($expected_campaigns_normalized[$name_lower])) {
                        $mapping = $expected_campaigns_normalized[$name_lower];
                        $setting_key = $mapping['setting_key'];
                        if (!isset($results['campaigns'][$setting_key])) {
                            $results['campaigns'][$setting_key] = [
                                'id' => $campaign_id,
                                'name' => $name
                            ];
                            $campaigns_found = true;
                        }
                    }
                }
                
                $total_campaigns_processed += count($items);
                
                // Get pagination info
                $items_count = $data['items_count'] ?? count($items);
                $total_items = $data['total_items'] ?? null;
                $next_item = $data['next_item'] ?? null;
                
                // Determine if there are more pages
                if ($total_items !== null) {
                    $has_more = $total_campaigns_processed < $total_items;
                } elseif ($next_item !== null) {
                    $has_more = $next_item < ($total_items ?? PHP_INT_MAX);
                } elseif (!empty($data['next_link'])) {
                    $has_more = true;
                } else {
                    $has_more = count($items) >= $limit;
                }
                
                // Update offset for next iteration
                $offset = $next_item ?? ($offset + $items_count);
                
                // Free memory after processing batch
                unset($items, $data, $campaigns_response);
                
                // Safety limit - prevent infinite loops (max 1000 items = 10 pages of 100)
                if ($offset >= 1000 || $total_campaigns_processed >= 1000) {
                    break;
                }
            }
            
            if (!$campaigns_found) {
                $results['errors'][] = 'Failed to fetch campaigns: No matching campaigns found';
            }
            
            // Log summary of what was matched vs what wasn't
            $fund_setting_to_name = array_flip($expected_funds);
            $campaign_setting_to_name = array_flip($expected_campaigns);
            
            $matched_fund_keys = array_keys($results['funds']);
            $matched_campaign_keys = array_keys($results['campaigns']);
            
            $matched_fund_names = array_filter(array_map(function($key) use ($fund_setting_to_name) {
                return $fund_setting_to_name[$key] ?? null;
            }, $matched_fund_keys));
            
            $matched_campaign_names = array_filter(array_map(function($key) use ($campaign_setting_to_name) {
                return $campaign_setting_to_name[$key] ?? null;
            }, $matched_campaign_keys));
            
            $unmatched_funds = array_diff(array_keys($expected_funds), $matched_fund_names);
            $unmatched_campaigns = array_diff(array_keys($expected_campaigns), $matched_campaign_names);
            
            // Don't save here - let the caller handle environment-specific mapping
            // Just return the results
            if (!empty($results['funds']) || !empty($results['campaigns'])) {
                $results['success'] = true;
                $this->helper->info('SettingsManager: Synced Fund and Campaign IDs', [
                    'matched_funds' => count($matched_fund_keys),
                    'matched_campaigns' => count($matched_campaign_keys),
                    'unmatched_funds' => array_values($unmatched_funds),
                    'unmatched_campaigns' => array_values($unmatched_campaigns)
                ]);
            } else {
                $results['success'] = false;
            }
            
            // Add warnings for unmatched items
            if (!empty($unmatched_funds)) {
                $results['errors'][] = sprintf(
                    'Warning: Could not find matching funds for: %s. Check that fund names in LGL match expected names.',
                    implode(', ', $unmatched_funds)
                );
            }
            if (!empty($unmatched_campaigns)) {
                $results['errors'][] = sprintf(
                    'Warning: Could not find matching campaigns for: %s. Check that campaign names in LGL match expected names.',
                    implode(', ', $unmatched_campaigns)
                );
            }
            
        } catch (\Exception $e) {
            $results['errors'][] = 'Exception: ' . $e->getMessage();
            $this->helper->error('SettingsManager: Sync Fund and Campaign IDs error', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
    }
    
    /**
     * Sync Group IDs from LGL API
     * 
     * Fetches current groups from LGL and updates settings
     * with matching IDs based on expected names/keys.
     * 
     * @return array Results with success status and matched IDs
     */
    public function syncGroups(): array {
        $results = [
            'success' => false,
            'groups' => [],
            'errors' => []
        ];
        
        try {
            // Clear cache for groups.json to ensure fresh data
            // Cache key format: 'api_request_' . md5('groups.json' . serialize([]))
            $groups_cache_key = 'api_request_' . md5('groups.json' . serialize([]));
            $this->cacheManager->delete($groups_cache_key);
            // Expected mappings (name or key → setting key)
            // We'll match by both name and key for flexibility
            $expected_groups = [
                // Role-based groups (match by name)
                'Teacher' => [
                    'setting_key' => 'role_group_mappings',
                    'role_key' => 'ui_teacher',
                    'group_key' => 'teacher'
                ],
                'Board Member' => [
                    'setting_key' => 'role_group_mappings',
                    'role_key' => 'ui_board',
                    'group_key' => 'board_member'
                ],
                'Staff' => [
                    'setting_key' => 'role_group_mappings',
                    'role_key' => 'ui_vip',
                    'group_key' => 'staff'
                ],
                'VIP' => [
                    'setting_key' => 'role_group_mappings',
                    'role_key' => 'ui_vip',
                    'group_key' => 'staff'
                ],
                // Scholarship groups (match by name variations)
                'Partial Scholarship Recipients' => [
                    'setting_key' => 'group_id_scholarship_partial',
                    'group_key' => 'scholarship_partial'
                ],
                'Scholarship - Partial' => [
                    'setting_key' => 'group_id_scholarship_partial',
                    'group_key' => 'scholarship_partial'
                ],
                'Full Scholarship Recipients' => [
                    'setting_key' => 'group_id_scholarship_full',
                    'group_key' => 'scholarship_full'
                ],
                'Scholarship - Full' => [
                    'setting_key' => 'group_id_scholarship_full',
                    'group_key' => 'scholarship_full'
                ],
            ];
            
            // Also match by group key for additional flexibility
            $expected_group_keys = [
                'teacher' => [
                    'setting_key' => 'role_group_mappings',
                    'role_key' => 'ui_teacher',
                    'group_key' => 'teacher'
                ],
                'board_member' => [
                    'setting_key' => 'role_group_mappings',
                    'role_key' => 'ui_board',
                    'group_key' => 'board_member'
                ],
                'staff' => [
                    'setting_key' => 'role_group_mappings',
                    'role_key' => 'ui_vip',
                    'group_key' => 'staff'
                ],
                'scholarship_partial' => [
                    'setting_key' => 'group_id_scholarship_partial',
                    'group_key' => 'scholarship_partial'
                ],
                'scholarship_full' => [
                    'setting_key' => 'group_id_scholarship_full',
                    'group_key' => 'scholarship_full'
                ],
            ];
            
            // Fetch groups from LGL API (bypass cache to get fresh data)
            $groups_response = $this->connection->makeRequest('groups.json', 'GET', [], false);
            
            if (!$groups_response['success']) {
                $results['errors'][] = 'Failed to fetch groups: ' . ($groups_response['error'] ?? 'Unknown error');
                return $results;
            }
            
            // Handle different response formats
            $groups_data = $groups_response['data'] ?? [];
            $groups = $groups_data['items'] ?? (is_array($groups_data) && isset($groups_data[0]['id']) ? $groups_data : []);
            
            if (empty($groups)) {
                $results['errors'][] = 'No groups found in LGL response';
                return $results;
            }
            
            // Build normalized expected groups map for case-insensitive matching (once, outside loop)
            $expected_groups_normalized = [];
            foreach ($expected_groups as $expected_name => $config) {
                $expected_groups_normalized[strtolower(trim($expected_name))] = $config;
            }
            
            $settings_to_update = [];
            $role_mappings_to_update = [];
            
            foreach ($groups as $group) {
                $group_id = (int) ($group['id'] ?? 0);
                $group_name = trim($group['name'] ?? '');
                $group_key = trim(strtolower($group['key'] ?? ''));
                
                if ($group_id <= 0) {
                    continue;
                }
                
                // Normalize group name for case-insensitive matching (but keep original for display)
                $group_name_normalized = trim($group_name);
                $group_name_lower = strtolower($group_name_normalized);
                
                $matched = false;
                $mapping = null;
                
                // Try to match by exact name first
                if (isset($expected_groups[$group_name])) {
                    $mapping = $expected_groups[$group_name];
                    $matched = true;
                }
                // Try case-insensitive name matching
                elseif (isset($expected_groups_normalized[$group_name_lower])) {
                    $mapping = $expected_groups_normalized[$group_name_lower];
                    $matched = true;
                }
                // Try to match by key
                elseif (!empty($group_key) && isset($expected_group_keys[$group_key])) {
                    $mapping = $expected_group_keys[$group_key];
                    $matched = true;
                }
                // Try fuzzy matching for scholarship groups (if not already matched)
                elseif (stripos($group_name, 'scholarship') !== false) {
                    $group_name_lower_for_match = strtolower($group_name);
                    // Check for partial scholarship
                    if (stripos($group_name_lower_for_match, 'partial') !== false) {
                        $mapping = [
                            'setting_key' => 'group_id_scholarship_partial',
                            'group_key' => 'scholarship_partial'
                        ];
                        $matched = true;
                    }
                    // Check for full scholarship
                    elseif (stripos($group_name_lower_for_match, 'full') !== false) {
                        $mapping = [
                            'setting_key' => 'group_id_scholarship_full',
                            'group_key' => 'scholarship_full'
                        ];
                        $matched = true;
                    }
                }
                
                if ($matched && $mapping) {
                    if ($mapping['setting_key'] === 'role_group_mappings') {
                        // Update role_group_mappings
                        $role_mappings_to_update[$mapping['role_key']] = [
                            'wp_role' => $mapping['role_key'],
                            'lgl_group_id' => $group_id,
                            'lgl_group_key' => $mapping['group_key']
                        ];
                        $results['groups'][$mapping['role_key']] = [
                            'id' => $group_id,
                            'name' => $group_name,
                            'key' => $group_key
                        ];
                    } else {
                        // Direct setting update (scholarship groups)
                        $settings_to_update[$mapping['setting_key']] = $group_id;
                        $results['groups'][$mapping['setting_key']] = [
                            'id' => $group_id,
                            'name' => $group_name,
                            'key' => $group_key
                        ];
                    }
                }
            }
            
            // Update role_group_mappings if we found any
            if (!empty($role_mappings_to_update)) {
                $current_role_mappings = $this->get('role_group_mappings') ?? [];
                
                foreach ($role_mappings_to_update as $role_key => $mapping) {
                    $current_role_mappings[$role_key] = $mapping;
                }
                
                $settings_to_update['role_group_mappings'] = $current_role_mappings;
            }
            
            // Update settings
            if (!empty($settings_to_update)) {
                $update_result = $this->update($settings_to_update);
                $results['success'] = $update_result;
                
                if ($update_result) {
                    $this->helper->info('SettingsManager: Groups synced successfully', [
                        'total_groups' => count($groups),
                        'matched_groups' => count($settings_to_update) + count($role_mappings_to_update),
                        'updated_settings' => array_keys($settings_to_update)
                    ]);
                } else {
                    $results['errors'][] = 'Failed to update settings';
                }
            } else {
                $results['errors'][] = 'No matching groups found';
            }
            
        } catch (\Exception $e) {
            $results['errors'][] = 'Exception: ' . $e->getMessage();
            $this->helper->error('SettingsManager: Sync Groups error', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
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
            $this->helper->info('SettingsManager: Settings reset to defaults');
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
        // CRITICAL: Use static cache to prevent repeated calls within same request
        // This prevents memory exhaustion from repeated database queries
        static $request_cache = null;
        static $request_cache_env = null;
        
        // Prevent infinite recursion
        if ($this->isLoadingSettings) {
            // Return minimal settings to break the cycle
            return get_option(self::OPTION_NAME, []);
        }
        
        // Check request-level cache first (fastest)
        $current_env = null;
        global $wpdb;
        $option_value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            self::OPTION_NAME
        ));
        
        if ($option_value) {
            $temp_settings = maybe_unserialize($option_value);
            if (is_array($temp_settings) && isset($temp_settings['environment'])) {
                $current_env = $temp_settings['environment'];
            }
        }
        
        if ($current_env === null || !in_array($current_env, ['dev', 'live'])) {
            $current_env = 'live';
        }
        
        // If we have cached settings for this environment, return them
        if ($request_cache !== null && $request_cache_env === $current_env) {
            return $request_cache;
        }
        
        $this->isLoadingSettings = true;
        
        try {
            // 1. Load from WP option first to determine environment
            // This must be done BEFORE checking cache to know which cache to check
            $settings = get_option(self::OPTION_NAME, []);
            
            // 2. Determine environment from settings (before applying defaults)
            $env = $settings['environment'] ?? 'live';
            if (!in_array($env, ['dev', 'live'])) {
                $env = 'live';
            }
            
            // 3. Try cache for the CORRECT environment (not both)
            $cache_prefix = 'lgl_cache_';
            $cache_key = $cache_prefix . $env . '_' . self::CACHE_KEY;
            $cached = get_transient($cache_key);
            
            if ($cached !== false && is_array($cached)) {
                // Cache in request-level cache
                $request_cache = $cached;
                $request_cache_env = $env;
                $this->isLoadingSettings = false;
                return $cached;
            }
            
            // 4. Apply defaults from schema (only for missing keys)
            $schema = $this->getSchema();
            foreach ($schema as $key => $field) {
                if (!isset($settings[$key])) {
                    $default = $field['default'];
                    $settings[$key] = is_callable($default) ? $default() : $default;
                }
            }
            
            // 5. Cache the result (use direct transient to avoid recursion)
            set_transient($cache_key, $settings, self::CACHE_TTL);
            
            // Cache in request-level cache
            $request_cache = $settings;
            $request_cache_env = $env;
            
            return $settings;
            
        } finally {
            $this->isLoadingSettings = false;
        }
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
            
            $this->helper->info('SettingsManager: Migrated settings from Carbon Fields', [
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
                
                $this->helper->info('SettingsManager: Migrated email blocking settings', [
                    'force_blocking' => (bool)$force_blocking,
                    'whitelist_count' => count($whitelist)
                ]);
            }
            
            update_option('lgl_email_blocking_settings_migrated', true);
        }
    }
    
    /**
     * Migrate legacy single API key to environment-based keys
     * 
     * Called automatically on first load if legacy keys exist
     */
    private function migrateLegacyApiSettings(): void {
        // Check if migration already done
        if (get_option('lgl_environment_migration_done')) {
            return;
        }
        
        // Load settings directly from database to avoid recursion (don't use getAll())
        $settings = get_option(self::OPTION_NAME, []);
        
        // If legacy keys exist but environment keys don't, migrate to live
        // BUT: Don't overwrite environment if it's already set (user may have set it to dev)
        if (!empty($settings['api_key']) && empty($settings['live_api_key'])) {
            // Update directly to avoid triggering getAll() again
            $settings['live_api_key'] = $settings['api_key'];
            $settings['live_api_url'] = $settings['api_url'] ?? '';
            
            // Only set environment to 'live' if it's not already set
            if (empty($settings['environment'])) {
                $settings['environment'] = 'live';
            }
            
            update_option(self::OPTION_NAME, $settings);
            
            // Clear both caches
            $cache_prefix = 'lgl_cache_';
            delete_transient($cache_prefix . 'dev_' . self::CACHE_KEY);
            delete_transient($cache_prefix . 'live_' . self::CACHE_KEY);
            
            $this->helper->info('SettingsManager: Migrated legacy API settings to live environment');
        }
        
        // Mark migration as complete
        update_option('lgl_environment_migration_done', true);
    }
    
    /**
     * Get default schema definition
     * 
     * @return array Schema definition
     */
    private function getDefaultSchema(): array {
        // Default LGL API endpoint URL
        $default_api_url = 'https://api.littlegreenlight.com/api/v1';
        
        return [
            // API Configuration (legacy - kept for backward compatibility)
            'api_url' => [
                'type' => 'string',
                'default' => $default_api_url,
                'validation' => ['required', 'url'],
                'sanitize' => 'sanitize_url'
            ],
            'api_key' => [
                'type' => 'string',
                'default' => '',
                'validation' => ['required', 'min:32'],
                'sanitize' => 'sanitize_text_field'
            ],
            
            // Environment Configuration
            'environment' => [
                'type' => 'string',
                'default' => 'live',
                'validation' => ['in:dev,live'],
                'sanitize' => 'sanitize_text_field',
                'label' => 'Environment',
                'description' => 'Select the LGL environment to use (dev or live)'
            ],
            
            // Dev Environment API Configuration
            'dev_api_url' => [
                'type' => 'string',
                'default' => $default_api_url,
                'validation' => ['url'],
                'sanitize' => 'sanitize_url',
                'label' => 'Dev API URL',
                'description' => 'LGL API URL for development environment'
            ],
            'dev_api_key' => [
                'type' => 'string',
                'default' => '',
                'sanitize' => 'sanitize_text_field',
                'label' => 'Dev API Key',
                'description' => 'LGL API key for development environment'
            ],
            
            // Live Environment API Configuration  
            'live_api_url' => [
                'type' => 'string',
                'default' => $default_api_url,
                'validation' => ['url'],
                'sanitize' => 'sanitize_url',
                'label' => 'Live API URL',
                'description' => 'LGL API URL for live/production environment'
            ],
            'live_api_key' => [
                'type' => 'string',
                'default' => '',
                'sanitize' => 'sanitize_text_field',
                'label' => 'Live API Key',
                'description' => 'LGL API key for live/production environment'
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
                'default' => 2437, // Correct value for dev database
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
            
            // Campaign ID Settings (Post-Remediation)
            'campaign_id_membership' => [
                'type' => 'integer',
                'default' => null, // Will be auto-populated from LGL
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Membership Campaign ID',
                'description' => 'LGL campaign ID for membership payments (auto-synced from LGL)'
            ],
            'campaign_id_language_classes' => [
                'type' => 'integer',
                'default' => null,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Language Programs Campaign ID',
                'description' => 'LGL campaign ID for language class registrations (auto-synced from LGL)'
            ],
            'campaign_id_events' => [
                'type' => 'integer',
                'default' => null,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Events Campaign ID',
                'description' => 'LGL campaign ID for event registrations (auto-synced from LGL)'
            ],
            
            // Dev Environment Fund IDs
            'dev_fund_id_membership' => [
                'type' => 'integer',
                'default' => 2437,  // Dev Membership Fund ID
                'sanitize' => 'intval',
                'label' => 'Dev Membership Fund ID',
                'description' => 'LGL fund ID for membership payments (dev environment)'
            ],
            'dev_fund_id_language_classes' => [
                'type' => 'integer',
                'default' => 2747,
                'sanitize' => 'intval',
                'label' => 'Dev Language Classes Fund ID',
                'description' => 'LGL fund ID for language class registrations (dev environment)'
            ],
            'dev_fund_id_events' => [
                'type' => 'integer',
                'default' => 4141,
                'sanitize' => 'intval',
                'label' => 'Dev Events Fund ID',
                'description' => 'LGL fund ID for event registrations (dev environment)'
            ],
            'dev_fund_id_general' => [
                'type' => 'integer',
                'default' => 4126,
                'sanitize' => 'intval',
                'label' => 'Dev General Fund ID',
                'description' => 'LGL fund ID for general donations (dev environment)'
            ],
            'dev_fund_id_family_member_slots' => [
                'type' => 'integer',
                'default' => 4147,
                'sanitize' => 'intval',
                'label' => 'Dev Family Member Slots Fund ID',
                'description' => 'LGL fund ID for Family Member slot purchases (dev environment)'
            ],
            
            // Live Environment Fund IDs
            'live_fund_id_membership' => [
                'type' => 'integer',
                'default' => 2432,  // Live Membership Fund ID (corrected)
                'sanitize' => 'intval',
                'label' => 'Live Membership Fund ID',
                'description' => 'LGL fund ID for membership payments (live environment)'
            ],
            'live_fund_id_language_classes' => [
                'type' => 'integer',
                'default' => 4132,
                'sanitize' => 'intval',
                'label' => 'Live Language Classes Fund ID',
                'description' => 'LGL fund ID for language class registrations (live environment)'
            ],
            'live_fund_id_events' => [
                'type' => 'integer',
                'default' => 4142,
                'sanitize' => 'intval',
                'label' => 'Live Events Fund ID',
                'description' => 'LGL fund ID for event registrations (live environment)'
            ],
            'live_fund_id_general' => [
                'type' => 'integer',
                'default' => 4127,
                'sanitize' => 'intval',
                'label' => 'Live General Fund ID',
                'description' => 'LGL fund ID for general donations (live environment)'
            ],
            'live_fund_id_family_member_slots' => [
                'type' => 'integer',
                'default' => 4147,
                'sanitize' => 'intval',
                'label' => 'Live Family Member Slots Fund ID',
                'description' => 'LGL fund ID for Family Member slot purchases (live environment)'
            ],
            
            // Dev Environment Campaign IDs
            'dev_campaign_id_membership' => [
                'type' => 'integer',
                'default' => null,
                'sanitize' => 'intval',
                'label' => 'Dev Membership Campaign ID',
                'description' => 'LGL campaign ID for membership payments (dev environment, auto-synced)'
            ],
            'dev_campaign_id_language_classes' => [
                'type' => 'integer',
                'default' => null,
                'sanitize' => 'intval',
                'label' => 'Dev Language Programs Campaign ID',
                'description' => 'LGL campaign ID for language class registrations (dev environment, auto-synced)'
            ],
            'dev_campaign_id_events' => [
                'type' => 'integer',
                'default' => null,
                'sanitize' => 'intval',
                'label' => 'Dev Events Campaign ID',
                'description' => 'LGL campaign ID for event registrations (dev environment, auto-synced)'
            ],
            
            // Live Environment Campaign IDs
            'live_campaign_id_membership' => [
                'type' => 'integer',
                'default' => null,
                'sanitize' => 'intval',
                'label' => 'Live Membership Campaign ID',
                'description' => 'LGL campaign ID for membership payments (live environment, auto-synced)'
            ],
            'live_campaign_id_language_classes' => [
                'type' => 'integer',
                'default' => null,
                'sanitize' => 'intval',
                'label' => 'Live Language Programs Campaign ID',
                'description' => 'LGL campaign ID for language class registrations (live environment, auto-synced)'
            ],
            'live_campaign_id_events' => [
                'type' => 'integer',
                'default' => null,
                'sanitize' => 'intval',
                'label' => 'Live Events Campaign ID',
                'description' => 'LGL campaign ID for event registrations (live environment, auto-synced)'
            ],
            
            // Group ID Settings (Auto-synced from LGL)
            'group_id_scholarship_partial' => [
                'type' => 'integer',
                'default' => null,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Partial Scholarship Group ID',
                'description' => 'LGL group ID for Partial Scholarship recipients (100-200% poverty level). Auto-synced from LGL when group name matches "Partial Scholarship Recipients" or "Scholarship - Partial"'
            ],
            'group_id_scholarship_full' => [
                'type' => 'integer',
                'default' => null,
                'validation' => ['integer', 'min:1'],
                'sanitize' => 'intval',
                'label' => 'Full Scholarship Group ID',
                'description' => 'LGL group ID for Full Scholarship recipients (Below 100% poverty level). Auto-synced from LGL when group name matches "Full Scholarship Recipients" or "Scholarship - Full"'
            ],
            
            // Role and Group Mapping Settings
            // Note: lgl_group_id values are auto-synced from LGL API via syncGroups() method
            'role_group_mappings' => [
                'type' => 'array',
                'default' => [
                    'ui_teacher' => [
                        'wp_role' => 'ui_teacher',
                        'lgl_group_id' => null, // Will be auto-populated from LGL (looks for group name "Teacher")
                        'lgl_group_key' => 'teacher'
                    ],
                    'ui_board' => [
                        'wp_role' => 'ui_board',
                        'lgl_group_id' => null, // Will be auto-populated from LGL (looks for group name "Board Member")
                        'lgl_group_key' => 'board_member'
                    ],
                    'ui_vip' => [
                        'wp_role' => 'ui_vip',
                        'lgl_group_id' => null, // Will be auto-populated from LGL (looks for group name "Staff" or "VIP")
                        'lgl_group_key' => 'staff'
                    ],
                ],
                'validation' => ['array'],
                'sanitize' => 'array',
                'label' => 'Role to LGL Group Mappings',
                'description' => 'Map WordPress roles to LGL Groups for automatic assignment. Group IDs are auto-synced from LGL API. Use the "Sync Groups" button to update IDs from your LGL instance.'
            ],
            'coupon_role_mappings' => [
                'type' => 'array',
                'default' => [
                    'TEACHER2024' => 'ui_teacher',
                    'BOARD2024' => 'ui_board',
                    'VIP2024' => 'ui_vip',
                    'SCHOLARSHIP25' => 'ui_member', // 100-200% poverty level
                    'SCHOLARSHIP100' => 'ui_member', // Below 100% poverty level
                ],
                'validation' => ['array'],
                'sanitize' => 'array',
                'label' => 'Coupon Code to Role Mappings',
                'description' => 'Map coupon codes (uppercase) to WordPress roles. Coupons trigger automatic role assignment and LGL group sync.'
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
     * Get current environment (dev or live)
     * 
     * @return string 'dev' or 'live'
     */
    public function getEnvironment(): string {
        return $this->get('environment', 'live');
    }
    
    /**
     * Get environment-specific API URL
     * 
     * @return string API URL for current environment
     */
    public function getApiUrlForEnvironment(): string {
        $env = $this->getEnvironment();
        $key = $env . '_api_url';
        $url = $this->get($key, '');
        
        // Fallback to legacy api_url if environment-specific key is empty
        if (empty($url)) {
            $url = $this->get('api_url', '');
        }
        
        return $url;
    }
    
    /**
     * Get environment-specific API key
     * 
     * @return string API key for current environment
     */
    public function getApiKeyForEnvironment(): string {
        $env = $this->getEnvironment();
        $key = $env . '_api_key';
        $api_key = $this->get($key, '');
        
        // Fallback to legacy api_key if environment-specific key is empty
        if (empty($api_key)) {
            $api_key = $this->get('api_key', '');
        }
        
        return $api_key;
    }
    
    /**
     * Get environment-specific fund ID
     * 
     * @param string $fund_type 'membership', 'language_classes', 'events', 'general', 'family_member_slots'
     * @return int Fund ID for current environment
     */
    public function getFundIdForEnvironment(string $fund_type): int {
        $env = $this->getEnvironment();
        $key = $env . '_fund_id_' . $fund_type;
        $fund_id = $this->get($key);
        
        // Fallback to legacy fund_id_* if environment-specific key is empty
        if (empty($fund_id)) {
            $legacy_key = 'fund_id_' . $fund_type;
            $fund_id = $this->get($legacy_key, $this->getDefaultFundId($fund_type));
        }
        
        return (int) $fund_id;
    }
    
    /**
     * Get environment-specific campaign ID
     * 
     * @param string $campaign_type 'membership', 'language_classes', 'events'
     * @return int|null Campaign ID for current environment
     */
    public function getCampaignIdForEnvironment(string $campaign_type): ?int {
        $env = $this->getEnvironment();
        $key = $env . '_campaign_id_' . $campaign_type;
        $campaign_id = $this->get($key);
        
        // Fallback to legacy campaign_id_* if environment-specific key is empty
        if (empty($campaign_id)) {
            $legacy_key = 'campaign_id_' . $campaign_type;
            $campaign_id = $this->get($legacy_key);
        }
        
        return $campaign_id ? (int) $campaign_id : null;
    }
    
    /**
     * Get default fund ID (fallback)
     * 
     * @param string $fund_type Fund type
     * @return int Default fund ID
     */
    private function getDefaultFundId(string $fund_type): int {
        // These defaults are fallbacks only - actual IDs come from API sync
        // Live environment defaults (used as fallback)
        $defaults = [
            'membership' => 2432,  // Live Membership Fund ID (corrected)
            'language_classes' => 4132,
            'events' => 4142,
            'general' => 4127,
            'family_member_slots' => 4147
        ];
        
        return $defaults[$fund_type] ?? 4127;
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

