<?php
/**
 * LGL Connection Manager
 * 
 * Manages API connections, requests, and responses for Little Green Light integration.
 * Handles authentication, HTTP requests, and response processing.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

use UpstateInternational\LGL\Core\CacheManager;

/**
 * Connection Class
 * 
 * Manages LGL API connections and HTTP operations
 */
class Connection {
    
    /**
     * Class instance
     * 
     * @var Connection|null
     */
    private static $instance = null;
    
    /**
     * LGL API settings
     * 
     * @var ApiSettings
     */
    private $lgl;
    
    /**
     * Current request URI
     * 
     * @var string
     */
    private $requestUri = '';
    
    /**
     * HTTP request arguments
     * 
     * @var array
     */
    private $args = [];
    
    /**
     * Current LGL object being processed
     * 
     * @var mixed
     */
    private $lglCurrentObject;
    
    /**
     * Flag indicating new constituent creation
     * 
     * @var bool
     */
    private $newConstituentFlag = false;
    
    /**
     * HTTP timeout for requests
     */
    const REQUEST_TIMEOUT = 30;
    
    /**
     * API version
     */
    const API_VERSION = 'v1';
    
    /**
     * Get instance
     * 
     * @return Connection
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
        $this->lgl = ApiSettings::getInstance();
        // Note: Connection settings are lazy-loaded when first request is made
        // to avoid circular dependencies during initialization
    }
    
    /**
     * Initialize connection settings (lazy-loaded on first use)
     */
    private function initializeConnection(): void {
        // Only initialize once
        if (!empty($this->args)) {
            return;
        }
        
        $api_key = $this->lgl->getApiKey();
        
        $this->args = [
            'timeout' => static::REQUEST_TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/LGL-Plugin-' . $this->getPluginVersion()
            ]
        ];
        
        // Add HTTP Basic Authentication if API key is available
        if (!empty($api_key)) {
            $this->args['headers']['Authorization'] = 'Basic ' . base64_encode($api_key . ':');
        }
    }
    
    /**
     * Make authenticated API request
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $data Request data
     * @param bool $use_cache Whether to use caching
     * @return array API response
     */
    public function makeRequest(string $endpoint, string $method = 'GET', array $data = [], bool $use_cache = true): array {
        try {
            // Ensure connection is initialized (lazy loading to avoid circular dependencies)
            $this->initializeConnection();
            
            // Generate cache key for GET requests
            $cache_key = null;
            if ($method === 'GET' && $use_cache) {
                $cache_key = 'api_request_' . md5($endpoint . serialize($data));
                $cached_response = CacheManager::get($cache_key);
                
                if ($cached_response !== false) {
                    Helper::getInstance()->debug('LGL Connection: Cache HIT for ' . $endpoint);
                    return $cached_response;
                }
            }
            
            // Rate limiting: Check if we can make a request
            $rateLimiter = RateLimiter::getInstance();
            
            if (!$rateLimiter->canMakeRequest()) {
                Helper::getInstance()->debug('LGL Connection: Rate limit reached, waiting for availability...');
                
                // Wait for rate limit window to reset
                if (!$rateLimiter->waitForAvailability()) {
                    return $this->createErrorResponse('Rate limit exceeded. Please try again later.');
                }
            }
            
            // Rate limiting: Wait minimum delay between requests
            $rateLimiter->waitIfNeeded();
            
            // Build request URI
            $this->requestUri = $this->buildRequestUri($endpoint);
            
            // Prepare request arguments
            $request_args = $this->prepareRequestArgs($method, $data);
            
            // Log request for debugging
            $this->logRequest($endpoint, $method, $data);
            
            // Make HTTP request
            $response = $this->executeHttpRequest($method, $request_args);
            
            // Record request for rate limiting (after successful request)
            $rateLimiter->recordRequest();
            
            // Process response
            $processed_response = $this->processResponse($response);
            
            // Cache successful GET responses
            if ($method === 'GET' && $use_cache && $cache_key && $this->isSuccessfulResponse($processed_response)) {
                CacheManager::set($cache_key, $processed_response, 3600); // 1 hour cache
            }
            
            // Log response for debugging
            $this->logResponse($endpoint, $processed_response);
            
            // Log rate limiter status
            $status = $rateLimiter->getStatus();
            if ($status['is_near_limit'] || $status['is_at_limit']) {
                Helper::getInstance()->debug('LGL Connection: ' . $rateLimiter->getStatusMessage());
            }
            
            return $processed_response;
            
        } catch (\Exception $e) {
            Helper::getInstance()->debug('LGL Connection Error: ' . $e->getMessage());
            return $this->createErrorResponse('Request failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Build complete request URI
     * 
     * @param string $endpoint API endpoint
     * @return string Complete URI
     */
    private function buildRequestUri(string $endpoint): string {
        $base_url = $this->lgl->getApiUrl();
        $api_key = $this->lgl->getApiKey();
        
        if (empty($base_url) || empty($api_key)) {
            throw new \Exception('LGL API URL or API key not configured');
        }
        
        // Remove leading slash from endpoint
        $endpoint = ltrim($endpoint, '/');
        
        // Build URI - base_url already includes version path
        $uri = rtrim($base_url, '/') . '/' . $endpoint;
        
        return $uri;
    }
    
    /**
     * Prepare request arguments
     * 
     * @param string $method HTTP method
     * @param array $data Request data
     * @return array Request arguments
     */
    private function prepareRequestArgs(string $method, array $data): array {
        $args = $this->args;
        $args['method'] = strtoupper($method);
        
        if (!empty($data)) {
            if ($method === 'GET') {
                // Add data as query parameters for GET requests
                $query_params = http_build_query($data);
                if ($query_params) {
                    $this->requestUri .= (strpos($this->requestUri, '?') !== false ? '&' : '?') . $query_params;
                }
            } else {
                // Add data as JSON body for other methods (preserve booleans, don't escape slashes)
                $json_body = json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
                
                // Debug: Log the actual JSON being sent
                if ($method === 'POST' && strpos($this->requestUri, 'constituents') !== false) {
                    Helper::getInstance()->debug('🔍 RAW JSON PAYLOAD: ' . $json_body);
                }
                
                $args['body'] = $json_body;
            }
        }
        
        return $args;
    }
    
    /**
     * Execute HTTP request
     * 
     * @param string $method HTTP method
     * @param array $args Request arguments
     * @return array|\WP_Error WordPress HTTP response
     */
    private function executeHttpRequest(string $method, array $args) {
        switch (strtoupper($method)) {
            case 'GET':
                return wp_remote_get($this->requestUri, $args);
            case 'POST':
                return wp_remote_post($this->requestUri, $args);
            case 'PUT':
                $args['method'] = 'PUT';
                return wp_remote_request($this->requestUri, $args);
            case 'DELETE':
                $args['method'] = 'DELETE';
                return wp_remote_request($this->requestUri, $args);
            default:
                throw new \Exception('Unsupported HTTP method: ' . $method);
        }
    }
    
    /**
     * Process HTTP response
     * 
     * @param array|\WP_Error $response WordPress HTTP response
     * @return array Processed response
     */
    private function processResponse($response): array {
        if (is_wp_error($response)) {
            return $this->createErrorResponse('HTTP Error: ' . $response->get_error_message());
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Handle HTTP errors
        if ($http_code >= 400) {
            return $this->createErrorResponse("HTTP {$http_code}: " . $body, $http_code);
        }
        
        // Parse JSON response
        $decoded = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->createErrorResponse('Invalid JSON response: ' . json_last_error_msg());
        }
        
        return [
            'success' => true,
            'http_code' => $http_code,
            'data' => $decoded,
            'raw_response' => $body
        ];
    }
    
    /**
     * Create standardized error response
     * 
     * @param string $message Error message
     * @param int $http_code HTTP status code
     * @return array Error response
     */
    private function createErrorResponse(string $message, int $http_code = 0): array {
        return [
            'success' => false,
            'error' => $message,
            'http_code' => $http_code,
            'data' => null
        ];
    }
    
    /**
     * Check if response indicates success
     * 
     * @param array $response Response array
     * @return bool True if successful
     */
    private function isSuccessfulResponse(array $response): bool {
        return isset($response['success']) && $response['success'] === true;
    }
    
    /**
     * Log request for debugging
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $data Request data
     */
    private function logRequest(string $endpoint, string $method, array $data): void {
        if (!$this->isDebugMode()) {
            return;
        }
        
        Helper::getInstance()->debug(sprintf(
            'LGL Connection Request: %s %s | Data: %s',
            strtoupper($method),
            $endpoint,
            json_encode($data)
        ));
    }
    
    /**
     * Log response for debugging
     * 
     * @param string $endpoint API endpoint
     * @param array $response Response data
     */
    private function logResponse(string $endpoint, array $response): void {
        if (!$this->isDebugMode()) {
            return;
        }
        
        $status = $response['success'] ? 'SUCCESS' : 'ERROR';
        $message = $response['success'] ? 'Request completed' : ($response['error'] ?? 'Unknown error');
        
        Helper::getInstance()->debug(sprintf(
            'LGL Connection Response: %s | %s | %s',
            $endpoint,
            $status,
            $message
        ));
    }
    
    /**
     * Get constituent by ID
     * 
     * @param string $constituent_id LGL constituent ID
     * @return array API response
     */
    public function getConstituent(string $constituent_id): array {
        return $this->makeRequest("constituents/{$constituent_id}");
    }
    
    /**
     * Create new constituent
     * 
     * @param array $constituent_data Constituent data
     * @return array API response
     */
    public function createConstituent(array $constituent_data): array {
        $this->newConstituentFlag = true;
        
        // Debug: Log the exact payload being sent to LGL
        $helper = Helper::getInstance();
        $helper->debug('🚀 Connection::createConstituent() - Payload being sent to LGL', [
            'payload_structure' => array_keys($constituent_data),
            'has_email_addresses' => isset($constituent_data['email_addresses']),
            'email_count' => isset($constituent_data['email_addresses']) ? count($constituent_data['email_addresses']) : 0,
            'has_phone_numbers' => isset($constituent_data['phone_numbers']),
            'phone_count' => isset($constituent_data['phone_numbers']) ? count($constituent_data['phone_numbers']) : 0,
            'has_street_addresses' => isset($constituent_data['street_addresses']),
            'address_count' => isset($constituent_data['street_addresses']) ? count($constituent_data['street_addresses']) : 0,
            'has_memberships' => isset($constituent_data['memberships']),
            'membership_count' => isset($constituent_data['memberships']) ? count($constituent_data['memberships']) : 0,
            'full_payload' => $constituent_data
        ]);
        
        return $this->makeRequest('constituents', 'POST', $constituent_data, false);
    }
    
    /**
     * Update existing constituent
     * 
     * @param string $constituent_id LGL constituent ID
     * @param array $constituent_data Updated constituent data
     * @return array API response
     */
    public function updateConstituent(string $constituent_id, array $constituent_data): array {
        return $this->makeRequest("constituents/{$constituent_id}", 'PUT', $constituent_data, false);
    }
    
    /**
     * Search constituents
     * 
     * @param array $search_criteria Search parameters
     * @return array API response
     */
    public function searchConstituents(array $search_criteria): array {
        return $this->makeRequest('constituents', 'GET', $search_criteria);
    }
    
    /**
     * Create payment record
     * 
     * @param array $payment_data Payment data
     * @return array API response
     */
    public function createPayment(array $payment_data): array {
        return $this->makeRequest('payments', 'POST', $payment_data, false);
    }
    
    /**
     * Get payment by ID
     * 
     * @param string $payment_id LGL payment ID
     * @return array API response
     */
    public function getPayment(string $payment_id): array {
        return $this->makeRequest("payments/{$payment_id}");
    }
    
    /**
     * Create relationship between constituents (LEGACY - use createConstituentRelationship instead)
     * 
     * @deprecated Use createConstituentRelationship() for constituent relationships
     * @param array $relationship_data Relationship data
     * @return array API response
     */
    public function createRelationship(array $relationship_data): array {
        return $this->makeRequest('relationships', 'POST', $relationship_data, false);
    }
    
    /**
     * Get relationships for constituent (LEGACY - use getConstituentRelationships instead)
     * 
     * @deprecated Use getConstituentRelationships() for constituent relationships
     * @param string $constituent_id LGL constituent ID
     * @return array API response
     */
    public function getRelationships(string $constituent_id): array {
        return $this->makeRequest("constituents/{$constituent_id}/relationships");
    }
    
    /**
     * Create constituent relationship in LGL
     * 
     * Creates a relationship between two constituents using the LGL constituent_relationships API.
     * Relationship types: Parent, Child, Spouse/Partner, Mother, Father, Daughter, Son, Employer, Employee
     * 
     * @param int $constituent_id The constituent ID to create the relationship for
     * @param array $relationship_data Relationship data:
     *   - 'related_constituent_id' (int, required): The ID of the related constituent
     *   - 'relationship_type_id' (int, required): ID of relationship type (use getRelationshipTypeId() to look up)
     *   - 'notes' (string, optional): Additional notes about the relationship
     * @return array API response with relationship ID on success
     */
    public function createConstituentRelationship(int $constituent_id, array $relationship_data): array {
        $helper = Helper::getInstance();
        $helper->debug('🔗 Connection: Creating constituent relationship', [
            'constituent_id' => $constituent_id,
            'relationship_data' => $relationship_data
        ]);
        
        $response = $this->makeRequest(
            "constituents/{$constituent_id}/constituent_relationships.json",
            'POST',
            $relationship_data,
            false
        );
        
        if ($response['success'] && isset($response['data'])) {
            $relationship_id = is_object($response['data']) ? 
                ($response['data']->id ?? null) : 
                ($response['data']['id'] ?? null);
            
            $helper->debug('✅ Connection: Constituent relationship created', [
                'constituent_id' => $constituent_id,
                'relationship_id' => $relationship_id,
                'relationship_type' => $relationship_data['relationship_type_name'] ?? 'unknown'
            ]);
        } else {
            $helper->debug('❌ Connection: Failed to create constituent relationship', [
                'constituent_id' => $constituent_id,
                'response' => $response
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get constituent relationships for a constituent
     * 
     * Retrieves all relationships for a given constituent from LGL.
     * 
     * @param int $constituent_id LGL constituent ID
     * @return array API response with relationships data
     */
    public function getConstituentRelationships(int $constituent_id): array {
        $helper = Helper::getInstance();
        $helper->debug('🔍 Connection: Getting constituent relationships', [
            'constituent_id' => $constituent_id
        ]);
        
        $response = $this->makeRequest(
            "constituents/{$constituent_id}/constituent_relationships.json",
            'GET',
            [],
            false
        );
        
        if ($response['success'] && isset($response['data'])) {
            $relationships = is_array($response['data']) ? $response['data'] : [];
            $helper->debug('✅ Connection: Retrieved constituent relationships', [
                'constituent_id' => $constituent_id,
                'count' => count($relationships)
            ]);
        }
        
        return $response;
    }
    
    /**
     * Delete constituent relationship from LGL
     * 
     * Deletes a specific constituent relationship by its relationship ID.
     * 
     * @param int $relationship_id LGL relationship ID
     * @return array API response
     */
    public function deleteConstituentRelationship(int $relationship_id): array {
        $helper = Helper::getInstance();
        $helper->debug('🗑️ Connection: Deleting constituent relationship', [
            'relationship_id' => $relationship_id
        ]);
        
        $response = $this->makeRequest(
            "constituent_relationships/{$relationship_id}.json",
            'DELETE',
            [],
            false
        );
        
        if ($response['success']) {
            $helper->debug('✅ Connection: Constituent relationship deleted', [
                'relationship_id' => $relationship_id
            ]);
        } else {
            $helper->debug('❌ Connection: Failed to delete constituent relationship', [
                'relationship_id' => $relationship_id,
                'response' => $response
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get relationship types from LGL API
     * 
     * Retrieves all available relationship types (Parent, Child, Spouse/Partner, etc.)
     * Results are cached to avoid repeated API calls.
     * 
     * @return array API response with relationship types
     */
    public function getRelationshipTypes(): array {
        $helper = Helper::getInstance();
        
        // Check cache first
        $cache_key = 'lgl_relationship_types';
        $cached = wp_cache_get($cache_key, 'lgl');
        
        if ($cached !== false) {
            $helper->debug('🔍 Connection: Using cached relationship types');
            return $cached;
        }
        
        $helper->debug('🔍 Connection: Fetching relationship types from LGL API');
        
        $response = $this->makeRequest('relationship_types', 'GET', [], false);
        
        if ($response['success']) {
            // Cache for 24 hours (relationship types don't change often)
            wp_cache_set($cache_key, $response, 'lgl', DAY_IN_SECONDS);
            $helper->debug('✅ Connection: Relationship types fetched and cached', [
                'count' => count($response['data'] ?? [])
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get relationship type ID by name
     * 
     * Looks up a relationship type ID by its name (e.g., "Parent" -> 123)
     * 
     * @param string $type_name Relationship type name (e.g., "Parent", "Child")
     * @return int|null Relationship type ID or null if not found
     */
    public function getRelationshipTypeId(string $type_name): ?int {
        $response = $this->getRelationshipTypes();
        
        if (!$response['success'] || empty($response['data'])) {
            Helper::getInstance()->debug('❌ Connection: Failed to get relationship types', [
                'response' => $response
            ]);
            return null;
        }
        
        // Extract relationship types from response - handle LGL API format with 'items' array
        $data = $response['data'];
        $types = [];
        
        if (is_array($data)) {
            // Check if it has an 'items' key (LGL API format)
            if (isset($data['items']) && is_array($data['items'])) {
                $types = $data['items'];
            } elseif (!empty($data) && (isset($data[0]['id']) || (is_object($data[0]) && isset($data[0]->id)))) {
                // Direct array of relationship types
                $types = $data;
            }
        } elseif (is_object($data)) {
            // Object with items property
            if (isset($data->items) && is_array($data->items)) {
                $types = $data->items;
            } elseif (isset($data->id)) {
                // Single relationship type object
                $types = [$data];
            }
        }
        
        if (empty($types)) {
            Helper::getInstance()->debug('❌ Connection: No relationship types found in response', [
                'data_type' => gettype($data),
                'data_keys' => is_array($data) ? array_keys($data) : (is_object($data) ? array_keys((array)$data) : 'not array/object'),
                'response' => $response
            ]);
            return null;
        }
        
        // Search for the relationship type by name
        foreach ($types as $type) {
            $name = is_object($type) ? ($type->name ?? null) : ($type['name'] ?? null);
            $id = is_object($type) ? ($type->id ?? null) : ($type['id'] ?? null);
            
            if ($name && strcasecmp($name, $type_name) === 0 && $id) {
                Helper::getInstance()->debug('✅ Connection: Found relationship type ID', [
                    'type_name' => $type_name,
                    'type_id' => $id
                ]);
                return (int) $id;
            }
        }
        
        // Log available types for debugging
        $available_names = array_map(function($t) {
            return is_object($t) ? ($t->name ?? null) : ($t['name'] ?? null);
        }, $types);
        
        Helper::getInstance()->debug('⚠️ Connection: Relationship type not found', [
            'type_name' => $type_name,
            'available_types' => array_filter($available_names),
            'total_types' => count($types)
        ]);
        
        return null;
    }
    
    /**
     * Test API connection
     * 
     * @return array Test result
     */
    public function testConnection(): array {
        try {
            $response = $this->makeRequest('constituents', 'GET', ['limit' => 1], false);
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => 'API connection successful',
                    'api_version' => static::API_VERSION
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'API connection failed: ' . ($response['error'] ?? 'Unknown error')
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get current object being processed
     * 
     * @return mixed Current LGL object
     */
    public function getCurrentObject() {
        return $this->lglCurrentObject;
    }
    
    /**
     * Set current object being processed
     * 
     * @param mixed $object LGL object
     */
    public function setCurrentObject($object): void {
        $this->lglCurrentObject = $object;
    }
    
    /**
     * Check if processing new constituent
     * 
     * @return bool True if new constituent
     */
    public function isNewConstituent(): bool {
        return $this->newConstituentFlag;
    }
    
    /**
     * Reset new constituent flag
     */
    public function resetNewConstituentFlag(): void {
        $this->newConstituentFlag = false;
    }
    
    /**
     * Get plugin version
     * 
     * @return string Plugin version
     */
    private function getPluginVersion(): string {
        return '2.0.0';
    }
    
    /**
     * Check if debug mode is enabled
     * 
     * @return bool True if debug mode
     */
    private function isDebugMode(): bool {
        return defined('WP_DEBUG') && WP_DEBUG;
    }
    
    /**
     * Get membership levels from LGL API
     * 
     * @return array
     */
    public function getMembershipLevels(): array {
        try {
            $response = $this->makeRequest('membership_levels');
            return $response['items'] ?? [];
        } catch (\Exception $e) {
            if ($this->isDebugMode()) {
                Helper::getInstance()->debug('LGL Connection: Failed to get membership levels: ' . $e->getMessage());
            }
            return [];
        }
    }
    
    /**
     * Search for constituent by name and email
     * 
     * @param string $name Full name to search for
     * @param string $email Email address to verify match
     * @return string|false LGL constituent ID if found, false otherwise
     */
    /**
     * Search for constituent by name and list of possible emails
     *
     * @param string $name
     * @param string|array $emails
     * @return array|null
     */
    public function searchByName(string $name, $emails) {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $helper->debug('🔍 Connection::searchByName() STARTED', [
            'name' => $name,
            'email' => $emails
        ]);

        $emailCandidates = $this->normalizeEmails($emails);
        
        try {
            // Clean up the name (remove URL encoding)
            $clean_name = str_replace('%20', ' ', $name);

            // Attempt direct email searches first (FAST - but verify exact email match)
            foreach ($emailCandidates as $emailCandidate) {
                $response = $this->makeRequest('constituents', 'GET', ['email' => $emailCandidate], false);
                
                if ($response['success'] && isset($response['data'])) {
                    $constituents = $this->extractConstituentsFromResponse($response['data']);
                    
                    if (!empty($constituents)) {
                        // Limit to first 10 results to avoid excessive API calls
                        // If there are many results, it's likely a base email match, not exact
                        $max_results = min(10, count($constituents));
                        $results_to_check = array_slice($constituents, 0, $max_results);
                        
                        // LGL returned matches for this email - check results to find one with exact email
                        foreach ($results_to_check as $match) {
                            $lgl_id = is_object($match) ? $match->id : $match['id'];
                            
                            // Get the actual email addresses from this constituent
                            $email_addresses = is_object($match) ? 
                                ($match->email_addresses ?? []) : 
                                ($match['email_addresses'] ?? []);
                            
                            // If email_addresses not in response, fetch full constituent to verify
                            if (empty($email_addresses)) {
                                $full_constituent = $this->getConstituent((string) $lgl_id);
                                if (!empty($full_constituent['success']) && !empty($full_constituent['data'])) {
                                    $full_data = $full_constituent['data'];
                                    $email_addresses = is_object($full_data) ? 
                                        ($full_data->email_addresses ?? []) : 
                                        ($full_data['email_addresses'] ?? []);
                                }
                            }
                            
                            // Verify this constituent actually has the exact email we're searching for
                            foreach ($email_addresses as $email_record) {
                                $address = is_object($email_record) ? 
                                    ($email_record->address ?? null) : 
                                    ($email_record['address'] ?? null);
                                    
                                // Case-insensitive comparison for exact match (including + tags)
                                if ($address && strcasecmp($address, $emailCandidate) === 0) {
                                    $helper->debug('✅ Email search found match with exact email verification', [
                                        'lgl_id' => $lgl_id,
                                        'email' => $emailCandidate,
                                        'checked_count' => array_search($match, $results_to_check) + 1,
                                        'total_results' => count($constituents),
                                        'checked_limit' => $max_results
                                    ]);
                                    
                                    return [
                                        'id' => $lgl_id,
                                        'email' => $emailCandidate,
                                        'method' => 'email'
                                    ];
                                }
                            }
                        }
                        
                        // Checked results but none had exact email - log and continue
                        $first_match = $constituents[0];
                        $first_lgl_id = is_object($first_match) ? $first_match->id : $first_match['id'];
                        $total_count = count($constituents);
                        $helper->debug('⚠️ LGL email search returned matches but none had exact email', [
                            'first_lgl_id' => $first_lgl_id,
                            'searched_email' => $emailCandidate,
                            'total_results' => $total_count,
                            'checked_limit' => $max_results,
                            'note' => $total_count > $max_results ? 
                                "Limited to first {$max_results} results (total: {$total_count})" :
                                'Continuing search - this may be a base email match (e.g., andrew@example.com vs andrew+1@example.com)'
                        ]);
                        // Continue to next email candidate or fall back to name search
                    }
                }
            }

            // FALLBACK: Try name-based search (less reliable, only if email search failed)
            $helper->debug('🔍 Email search failed, trying name search', ['name' => $clean_name]);
            
            $response = $this->makeRequest('constituents', 'GET', ['search' => $clean_name], false);
            
            if ($response['success'] && isset($response['data'])) {
                $constituents = $this->extractConstituentsFromResponse($response['data']);
                
                if (!empty($constituents)) {
                    // If we have email candidates, verify ALL matches to find one with matching email
                    if (!empty($emailCandidates)) {
                        // Check all constituents returned by name search
                        foreach ($constituents as $match) {
                            $lgl_id = is_object($match) ? $match->id : $match['id'];
                            
                            // Check if email_addresses are included in the response
                            $email_addresses = is_object($match) ? 
                                ($match->email_addresses ?? null) : 
                                ($match['email_addresses'] ?? null);
                            
                            // If email_addresses not in response, fetch full constituent to verify
                            if (empty($email_addresses) || !is_array($email_addresses)) {
                                $full_constituent = $this->getConstituent((string) $lgl_id);
                                if (!empty($full_constituent['success']) && !empty($full_constituent['data'])) {
                                    $full_data = $full_constituent['data'];
                                    $email_addresses = is_object($full_data) ? 
                                        ($full_data->email_addresses ?? []) : 
                                        ($full_data['email_addresses'] ?? []);
                                }
                            }
                                
                            if (!empty($email_addresses) && is_array($email_addresses)) {
                                // Check if any of the candidate emails match
                                foreach ($email_addresses as $email_record) {
                                    $address = is_object($email_record) ? 
                                        ($email_record->address ?? null) : 
                                        ($email_record['address'] ?? null);
                                        
                                    foreach ($emailCandidates as $emailCandidate) {
                                        if ($address && strcasecmp($address, $emailCandidate) === 0) {
                                            $helper->debug('✅ Name search found match with email verification', [
                                                'lgl_id' => $lgl_id,
                                                'email' => $emailCandidate,
                                                'checked_count' => array_search($match, $constituents) + 1,
                                                'total_results' => count($constituents)
                                            ]);
                                            return [
                                                'id' => $lgl_id,
                                                'email' => $emailCandidate,
                                                'method' => 'name'
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                        
                        // No matches found after checking all constituents
                        $helper->debug('⚠️ Name search found results but email verification failed for all matches', [
                            'total_results' => count($constituents),
                            'searched_emails' => $emailCandidates
                        ]);
                    } else {
                        // No email candidates provided, just return first match by name
                        $first_match = $constituents[0];
                        $lgl_id = is_object($first_match) ? $first_match->id : $first_match['id'];
                        $helper->debug('✅ Name match confirmed (no emails provided)', ['lgl_id' => $lgl_id]);
                        return [
                            'id' => $lgl_id,
                            'email' => null,
                            'method' => 'name'
                        ];
                    }
                }
            }
            
            $helper->debug('❌ No matching constituent found with any search method');
            return null;
            
        } catch (\Exception $e) {
            $helper->debug('❌ Connection::searchByName() - Exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return null;
        }
    }

    /**
     * Normalize email input into array
     * Also extracts base email for tagged emails (e.g., user+tag@example.com -> user@example.com)
     *
     * @param string|array $emails
     * @return array<int, string>
     */
    private function normalizeEmails($emails): array {
        $list = [];
        if (is_array($emails)) {
            $list = $emails;
        } elseif (is_string($emails) && !empty($emails)) {
            $list = [$emails];
        }
        $normalized = [];
        foreach ($list as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }
            $email_lower = strtolower($email);
            $normalized[] = $email_lower;
            
            // If email has a + tag (e.g., user+tag@example.com), also add base email
            if (strpos($email_lower, '+') !== false && strpos($email_lower, '@') !== false) {
                $parts = explode('@', $email_lower);
                if (count($parts) === 2) {
                    $local = explode('+', $parts[0])[0]; // Get part before +
                    $base_email = $local . '@' . $parts[1];
                    if ($base_email !== $email_lower) {
                        $normalized[] = $base_email;
                    }
                }
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * Search LGL constituents by email address
     *
     * @param string $email
     * @return array|null
     */
    private function searchByEmail(string $email): ?array {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();

        $helper->debug('🔍 Connection::searchByEmail()', ['email' => $email]);

        $response = $this->makeRequest('constituents', 'GET', ['email' => $email], false);
        if (!$this->isSuccessfulResponse($response) || empty($response['data'])) {
            return null;
        }

        $constituents = $this->extractConstituentsFromResponse($response['data']);
        foreach ($constituents as $constituent) {
            if ($this->verifyConstituentEmail($constituent, $email)) {
                $lgl_id = is_object($constituent) ? $constituent->id : $constituent['id'];
                $helper->debug('✅ Email match confirmed', [
                    'lgl_id' => $lgl_id,
                    'email' => $email
                ]);
                return [
                    'id' => $lgl_id,
                    'email' => $email
                ];
            }
        }

        return null;
    }
    
    
    /**
     * Add membership payment to LGL
     * 
     * @param int $lgl_id LGL constituent ID
     * @param array $request Request data containing payment info
     * @param string $payment_type Payment type (online, check, etc.)
     * @return int|false Payment ID on success, false on failure
     */
    public function addMembershipPayment(int $lgl_id, array $request, string $payment_type = 'online') {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $helper->debug('💳 Connection::addMembershipPayment() STARTED', [
            'lgl_id' => $lgl_id,
            'payment_type' => $payment_type
        ]);
        
        // Extract payment data from request
        $amount = $request['payment_amount'] ?? $request['total'] ?? 0;
        $date = $request['payment_date'] ?? date('Y-m-d');
        $method = $payment_type === 'online' ? 'Credit Card' : 'Check';
        
        $payment_data = [
            'constituent_id' => $lgl_id,
            'amount' => $amount,
            'date' => $date,
            'payment_method' => $method,
            'notes' => 'Membership payment via WordPress LGL Integration',
            'fund_name' => 'Membership Dues'
        ];
        
        $helper->debug('💳 Connection::addMembershipPayment() - Payment data', $payment_data);
        
        try {
            $response = $this->createPayment($payment_data);
            
            if (isset($response['data']['id'])) {
                $helper->debug('✅ Connection::addMembershipPayment() - Payment created', [
                    'payment_id' => $response['data']['id']
                ]);
                return $response['data']['id'];
            } else {
                $helper->debug('❌ Connection::addMembershipPayment() - Failed to create payment', [
                    'response' => $response
                ]);
                return false;
            }
        } catch (\Exception $e) {
            $helper->debug('❌ Connection::addMembershipPayment() - Exception', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Extract constituents array from API response
     * 
     * @param mixed $data Response data
     * @return array Array of constituents
     */
    private function extractConstituentsFromResponse($data): array {
        // Removed excessive debug logging for performance
        // $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        // $helper->debug('🔍 extractConstituentsFromResponse() - Raw data analysis', ...);
        
        // Handle different response formats
        if (is_array($data)) {
            // Check if it has an 'items' key (LGL API format)
            if (isset($data['items']) && is_array($data['items'])) {
                // $helper->debug('✅ Found LGL API format with items array', ['count' => count($data['items'])]);
                return $data['items'];
            }
            // Check if it's a direct array of constituents
            if (!empty($data) && (isset($data[0]['id']) || (is_object($data[0]) && isset($data[0]->id)))) {
                // $this->helper->debug('✅ Found direct array of constituents', ['count' => count($data)]);
                return $data;
            }
            // Check if it's an array with numeric keys containing constituent data
            $constituents = [];
            foreach ($data as $item) {
                if ((is_array($item) && isset($item['id'])) || (is_object($item) && isset($item->id))) {
                    $constituents[] = $item;
                }
            }
            if (!empty($constituents)) {
                // $this->helper->debug('✅ Extracted constituents from array', ['count' => count($constituents)]);
                return $constituents;
            }
        } elseif (is_object($data)) {
            // Object with items property
            if (isset($data->items) && is_array($data->items)) {
                // $this->helper->debug('✅ Found object with items property', ['count' => count($data->items)]);
                return $data->items;
            }
            // Single constituent object
            if (isset($data->id)) {
                // $this->helper->debug('✅ Found single constituent object', ['id' => $data->id]);
                return [$data];
            }
            // Convert object to array and check again
            $data_array = (array)$data;
            if (isset($data_array['items']) && is_array($data_array['items'])) {
                // $this->helper->debug('✅ Found items in converted array', ['count' => count($data_array['items'])]);
                return $data_array['items'];
            }
        }
        
        Helper::getInstance()->debug('⚠️ Unknown response format in extractConstituentsFromResponse', [
            'data_type' => gettype($data),
            'data_keys' => is_object($data) ? array_keys((array)$data) : (is_array($data) ? array_keys($data) : 'not array/object'),
            'full_data' => $data
        ]);
        
        return [];
    }
    
    /**
     * Verify that a constituent has the specified email address
     * 
     * @param mixed $constituent Constituent object or array
     * @param string $email Email to verify
     * @return bool True if email matches
     */
    private function verifyConstituentEmail($constituent, string $email): bool {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $constituent_id = is_object($constituent) ? $constituent->id : $constituent['id'];
        
        // FIRST: Check if email_addresses are already included in the constituent data (most efficient)
        $email_addresses = is_object($constituent) ? 
            ($constituent->email_addresses ?? null) : 
            ($constituent['email_addresses'] ?? null);
            
        if (!empty($email_addresses) && is_array($email_addresses)) {
            foreach ($email_addresses as $email_record) {
                $address = is_object($email_record) ? 
                    ($email_record->address ?? null) : 
                    ($email_record['address'] ?? null);
                    
                if ($address && strcasecmp($address, $email) === 0) {
                    $helper->debug('✅ Email match confirmed (from constituent data)', [
                        'constituent_id' => $constituent_id,
                        'matched_email' => $address
                    ]);
                    return true;
                }
            }
            // Email addresses were present but didn't match
            return false;
        }
        
        // FALLBACK: Make separate API call if email_addresses not included in response
        try {
            $emails_response = $this->makeRequest("constituents/{$constituent_id}/email_addresses", 'GET', [], false);
            
            if ($emails_response['success'] && isset($emails_response['data'])) {
                $emails = $this->extractConstituentsFromResponse($emails_response['data']);
                
                foreach ($emails as $email_record) {
                    $address = is_object($email_record) ? $email_record->address : $email_record['address'];
                    if (strcasecmp($address, $email) === 0) {
                        $helper->debug('✅ Email match confirmed', [
                            'constituent_id' => $constituent_id,
                            'matched_email' => $address
                        ]);
                        return true;
                    }
                }
            }
            
            // $helper->debug('❌ No email match for constituent', [
            //     'constituent_id' => $constituent_id
            // ]);
            return false;
            
        } catch (\Exception $e) {
            $helper->debug('❌ Error verifying email', [
                'constituent_id' => $constituent_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get constituent data with memberships
     * 
     * @param string $lgl_id LGL constituent ID
     * @return mixed Constituent data object
     */
    public function getConstituentData(string $lgl_id) {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $helper->debug('🔍 Connection::getConstituentData()', ['lgl_id' => $lgl_id]);
        
        try {
            $response = $this->makeRequest("constituents/{$lgl_id}", 'GET', [], false);
            
            if ($response['success'] && isset($response['data'])) {
                $helper->debug('✅ Constituent data retrieved successfully');
                return $response['data'];
            } else {
                $helper->debug('❌ Failed to retrieve constituent data', $response);
                return null;
            }
            
        } catch (\Exception $e) {
            $helper->debug('❌ Error retrieving constituent data', [
                'lgl_id' => $lgl_id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Get all memberships for a constituent
     * 
     * Uses: GET /api/v1/constituents/{lgl_id}/memberships
     * Returns ALL memberships (active and inactive) for comprehensive management
     * 
     * @param string $lgl_id LGL constituent ID
     * @return array API response with memberships
     */
    public function getMemberships(string $lgl_id): array {
        $endpoint = "constituents/{$lgl_id}/memberships";
        
        if (function_exists('lgl_log')) {
            lgl_log('🔍 Connection::getMemberships()', [
                'endpoint' => $endpoint,
                'lgl_id' => $lgl_id
            ]);
        }
        
        $response = $this->makeRequest($endpoint, 'GET', [], false);
        
        if (function_exists('lgl_log')) {
            lgl_log('📥 Memberships Response', [
                'success' => $response['success'] ?? false,
                'count' => isset($response['data']['items']) ? count($response['data']['items']) : 0
            ]);
        }
        
        return $response;
    }
    
    /**
     * Update membership in LGL
     * 
     * @param string $lgl_id LGL constituent ID
     * @param string $membership_id Membership ID
     * @param array $membership_data Membership data
     * @return bool Success status
     */
    public function updateMembership(string $membership_id, array $membership_data): array {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        // CRITICAL FIX: Use direct membership endpoint, not nested under constituents
        // Legacy used: PUT https://api.littlegreenlight.com/api/v1/memberships/{id}.json
        // NOT: PUT constituents/{lgl_id}/memberships/{id} (this returns 404)
        $endpoint = "memberships/{$membership_id}";
        
        if (function_exists('lgl_log')) {
            lgl_log('🔄 Connection::updateMembership() - Using direct endpoint', [
                'endpoint' => $endpoint,
                'membership_id' => $membership_id,
                'membership_data' => $membership_data
            ]);
        }
        
        try {
            $response = $this->makeRequest($endpoint, 'PUT', $membership_data, false);
            
            if (function_exists('lgl_log')) {
                lgl_log('📥 Membership Update Response', $response);
            }
            
            if (!empty($response['success'])) {
                $helper->debug('✅ Membership updated successfully', ['membership_id' => $membership_id]);
            } else {
                $helper->debug('❌ Failed to update membership', $response);
            }
            
            return $response;
            
        } catch (\Exception $e) {
            $helper->debug('❌ Error updating membership', [
                'membership_id' => $membership_id,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Add membership to LGL constituent
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $membership_data Membership data
     * @return string|false Membership ID on success, false on failure
     */
    /**
     * Add payment/gift to LGL constituent
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $payment_data Payment data
     * @return string|false Payment ID on success, false on failure
     */
    public function addPayment(string $lgl_id, array $payment_data) {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $helper->debug('💳 Connection::addPayment()', [
            'lgl_id' => $lgl_id,
            'payment_data' => $payment_data
        ]);
        
        try {
            $response = $this->makeRequest(
                "constituents/{$lgl_id}/gifts.json",
                'POST',
                $payment_data,
                false
            );
            
            if (($response['success'] == 1 || $response['success'] === true) && isset($response['data']['id'])) {
                $payment_id = $response['data']['id'];
                $helper->debug('✅ Payment added successfully', [
                    'payment_id' => $payment_id
                ]);
                return [
                    'success' => true,
                    'id' => $payment_id,
                    'data' => $response['data']
                ];
            } else {
                $helper->debug('❌ Failed to add payment', $response);
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'Unknown error',
                    'http_code' => $response['http_code'] ?? null
                ];
            }
            
        } catch (\Exception $e) {
            $helper->debug('❌ Error adding payment', [
                'lgl_id' => $lgl_id,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get connection statistics
     * 
     * @return array Connection statistics
     */
    public function getConnectionStats(): array {
        return [
            'api_version' => static::API_VERSION,
            'timeout' => static::REQUEST_TIMEOUT,
            'base_url' => $this->lgl->getApiUrl(),
            'has_api_key' => !empty($this->lgl->getApiKey()),
            'debug_mode' => $this->isDebugMode()
        ];
    }
    
    /**
     * Add email address to existing constituent
     * (Multi-request pattern - matching legacy lgl_add_object)
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $email_data Email data array
     * @return array API response
     */
    public function addEmailAddress(string $lgl_id, array $email_data): array {
        $endpoint = "constituents/{$lgl_id}/email_addresses";
        return $this->makeRequest($endpoint, 'POST', $email_data, false);
    }
    
    /**
     * Update existing email address
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $email_id Email address ID
     * @param array $email_data Updated email data array
     * @return array API response
     */
    public function updateEmailAddress(string $lgl_id, int $email_id, array $email_data): array {
        $helper = Helper::getInstance();
        $endpoint = "constituents/{$lgl_id}/email_addresses/{$email_id}";
        
        // Ensure id is included in payload (some APIs require it)
        $update_payload = array_merge($email_data, ['id' => $email_id]);
        
        $helper->debug('🔧 updateEmailAddress: Making PUT request', [
            'endpoint' => $endpoint,
            'email_id' => $email_id,
            'update_payload' => $update_payload
        ]);
        
        $response = $this->makeRequest($endpoint, 'PUT', $update_payload, false);
        
        $helper->debug('📥 updateEmailAddress: Raw API response', [
            'success' => $response['success'] ?? false,
            'http_code' => $response['http_code'] ?? null,
            'error' => $response['error'] ?? null
        ]);
        
        return $response;
    }
    
    /**
     * Update existing phone number
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $phone_id Phone number ID
     * @param array $phone_data Updated phone data array
     * @return array API response
     */
    public function updatePhoneNumber(string $lgl_id, int $phone_id, array $phone_data): array {
        $helper = Helper::getInstance();
        $endpoint = "constituents/{$lgl_id}/phone_numbers/{$phone_id}";
        
        // Ensure id is included in payload (some APIs require it)
        $update_payload = array_merge($phone_data, ['id' => $phone_id]);
        
        $helper->debug('🔧 updatePhoneNumber: Making PUT request', [
            'endpoint' => $endpoint,
            'phone_id' => $phone_id,
            'update_payload' => $update_payload
        ]);
        
        $response = $this->makeRequest($endpoint, 'PUT', $update_payload, false);
        
        $helper->debug('📥 updatePhoneNumber: Raw API response', [
            'success' => $response['success'] ?? false,
            'http_code' => $response['http_code'] ?? null,
            'error' => $response['error'] ?? null
        ]);
        
        return $response;
    }
    
    /**
     * Update existing street address
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $address_id Street address ID
     * @param array $address_data Updated address data array
     * @return array API response
     */
    public function updateStreetAddress(string $lgl_id, int $address_id, array $address_data): array {
        $helper = Helper::getInstance();
        $endpoint = "constituents/{$lgl_id}/street_addresses/{$address_id}";
        
        // Ensure id is included in payload (some APIs require it)
        $update_payload = array_merge($address_data, ['id' => $address_id]);
        
        $helper->debug('🔧 updateStreetAddress: Making PUT request', [
            'endpoint' => $endpoint,
            'address_id' => $address_id,
            'address_data' => $address_data,
            'update_payload' => $update_payload
        ]);
        
        $response = $this->makeRequest($endpoint, 'PUT', $update_payload, false);
        
        $helper->debug('📥 updateStreetAddress: Raw API response', [
            'endpoint' => $endpoint,
            'success' => $response['success'] ?? false,
            'http_code' => $response['http_code'] ?? null,
            'error' => $response['error'] ?? null,
            'response_data' => $response['data'] ?? null,
            'raw_response' => $response['raw_response'] ?? null
        ]);
        
        return $response;
    }
    
    /**
     * Safely add email address - checks for duplicates first
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $email_data Email data array
     * @return array API response with 'skipped' flag if duplicate found
     */
    public function addEmailAddressSafe(string $lgl_id, array $email_data): array {
        $helper = Helper::getInstance();
        $email_address = strtolower(trim($email_data['address'] ?? ''));
        
        if (empty($email_address)) {
            return ['success' => false, 'error' => 'No email address provided'];
        }
        
        // CRITICAL: Add debug logging at the START to confirm this method is being called
        $helper->debug('🔍 addEmailAddressSafe: START - Checking for duplicate', [
            'lgl_id' => $lgl_id,
            'email' => $email_address
        ]);
        
        // Check if email already exists
        $existing_emails = $this->getConstituentEmails($lgl_id);
        $helper->debug('🔍 addEmailAddressSafe: Found existing emails', [
            'count' => count($existing_emails),
            'emails' => array_map(function($e) {
                return is_array($e) ? ($e['address'] ?? 'unknown') : ($e->address ?? 'unknown');
            }, $existing_emails)
        ]);
        
        foreach ($existing_emails as $existing_email) {
            // Handle both array and object formats
            $existing_address = '';
            if (is_array($existing_email)) {
                $existing_address = strtolower(trim($existing_email['address'] ?? ''));
            } elseif (is_object($existing_email)) {
                $existing_address = strtolower(trim($existing_email->address ?? ''));
            }
            
            if ($existing_address && $existing_address === $email_address) {
                $helper->debug('⚠️ Email already exists, skipping duplicate', [
                    'lgl_id' => $lgl_id,
                    'email' => $email_address,
                    'existing_email_id' => is_array($existing_email) ? ($existing_email['id'] ?? null) : ($existing_email->id ?? null)
                ]);
                return ['success' => true, 'skipped' => true, 'message' => 'Email already exists'];
            }
        }
        
        // Email doesn't exist, add it
        $helper->debug('✅ addEmailAddressSafe: Email is new, adding', ['email' => $email_address]);
        return $this->addEmailAddress($lgl_id, $email_data);
    }
    
    /**
     * Update or add email address - updates if exists and different, adds if new
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $email_data Email data array
     * @return array API response with 'updated', 'added', or 'skipped' flag
     */
    public function updateOrAddEmailAddress(string $lgl_id, array $email_data): array {
        $helper = Helper::getInstance();
        $email_address = strtolower(trim($email_data['address'] ?? ''));
        
        if (empty($email_address)) {
            return ['success' => false, 'error' => 'No email address provided'];
        }
        
        $helper->debug('🔄 updateOrAddEmailAddress: Checking existing emails', [
            'lgl_id' => $lgl_id,
            'email' => $email_address
        ]);
        
        // Check if email already exists
        $existing_emails = $this->getConstituentEmails($lgl_id);
        $exact_match_found = false;
        $exact_match_id = null;
        
        foreach ($existing_emails as $existing_email) {
            $existing_address = '';
            $existing_id = null;
            
            if (is_array($existing_email)) {
                $existing_address = strtolower(trim($existing_email['address'] ?? ''));
                $existing_id = $existing_email['id'] ?? null;
            } elseif (is_object($existing_email)) {
                $existing_address = strtolower(trim($existing_email->address ?? ''));
                $existing_id = $existing_email->id ?? null;
            }
            
            if ($existing_address && $existing_address === $email_address) {
                $exact_match_found = true;
                $exact_match_id = $existing_id;
                // Email exists - check if it needs updating
                $needs_update = false;
                if (is_array($existing_email)) {
                    $existing_type = $existing_email['email_address_type_id'] ?? null;
                    $new_type = $email_data['email_address_type_id'] ?? null;
                    $existing_preferred = !empty($existing_email['is_preferred']);
                    $new_preferred = !empty($email_data['is_preferred']);
                    if ($existing_type != $new_type || $existing_preferred != $new_preferred) {
                        $needs_update = true;
                    }
                }
                
                if ($needs_update && $existing_id) {
                    $helper->debug('🔄 updateOrAddEmailAddress: Updating existing email', [
                        'email_id' => $existing_id,
                        'email' => $email_address
                    ]);
                    $result = $this->updateEmailAddress($lgl_id, $existing_id, $email_data);
                    return array_merge($result, ['updated' => true]);
                } else {
                    $helper->debug('✅ updateOrAddEmailAddress: Email unchanged, skipping', [
                        'email' => $email_address
                    ]);
                    return ['success' => true, 'skipped' => true, 'message' => 'Email unchanged'];
                }
            }
        }
        
        // If email address is different but we have existing emails, update the preferred one
        if (!$exact_match_found && !empty($existing_emails)) {
            // Find preferred email to replace
            $preferred_email_id = null;
            foreach ($existing_emails as $check_email) {
                $is_preferred = false;
                $check_id = null;
                if (is_array($check_email)) {
                    $is_preferred = !empty($check_email['is_preferred']);
                    $check_id = $check_email['id'] ?? null;
                } elseif (is_object($check_email)) {
                    $is_preferred = !empty($check_email->is_preferred);
                    $check_id = $check_email->id ?? null;
                }
                if ($is_preferred && $check_id) {
                    $preferred_email_id = $check_id;
                    break;
                }
            }
            
            // If no preferred found, use first one
            if (!$preferred_email_id && !empty($existing_emails[0])) {
                $first_email = $existing_emails[0];
                $preferred_email_id = is_array($first_email) ? ($first_email['id'] ?? null) : ($first_email->id ?? null);
            }
            
            if ($preferred_email_id) {
                $helper->debug('🔄 updateOrAddEmailAddress: Updating existing email (replacing preferred/first)', [
                    'email_id' => $preferred_email_id,
                    'new_email' => $email_address
                ]);
                $result = $this->updateEmailAddress($lgl_id, $preferred_email_id, $email_data);
                return array_merge($result, ['updated' => true]);
            }
        }
        
        // Email doesn't exist, add it
        $helper->debug('➕ updateOrAddEmailAddress: Adding new email', ['email' => $email_address]);
        $result = $this->addEmailAddress($lgl_id, $email_data);
        return array_merge($result, ['added' => true]);
    }
    
    /**
     * Get all email addresses for a constituent
     * 
     * @param string $lgl_id LGL constituent ID
     * @return array Array of email address records
     */
    private function getConstituentEmails(string $lgl_id): array {
        $helper = Helper::getInstance();
        try {
            $response = $this->makeRequest("constituents/{$lgl_id}/email_addresses", 'GET', [], false);
            if ($response['success'] && isset($response['data'])) {
                $data = $response['data'];
                
                // Log the raw response format for debugging
                $helper->debug('🔍 getConstituentEmails: Raw response format', [
                    'lgl_id' => $lgl_id,
                    'data_type' => gettype($data),
                    'is_array' => is_array($data),
                    'has_items' => is_array($data) && isset($data['items']),
                    'first_item_has_address' => is_array($data) && !empty($data) && isset($data[0]['address']),
                    'data_keys' => is_array($data) ? array_keys($data) : 'not_array'
                ]);
                
                // Handle different response formats
                if (is_array($data)) {
                    // Check if it's a direct array of email objects
                    if (!empty($data) && (isset($data[0]['address']) || (is_object($data[0]) && isset($data[0]->address)))) {
                        $helper->debug('🔍 getConstituentEmails: Direct array format', ['count' => count($data)]);
                        return $data;
                    }
                    // Check if it has 'items' key
                    if (isset($data['items']) && is_array($data['items'])) {
                        $helper->debug('🔍 getConstituentEmails: Items array format', ['count' => count($data['items'])]);
                        return $data['items'];
                    }
                    // Check if it's an empty array
                    if (empty($data)) {
                        $helper->debug('🔍 getConstituentEmails: Empty array');
                        return [];
                    }
                } elseif (is_object($data)) {
                    // Object with items property
                    if (isset($data->items) && is_array($data->items)) {
                        $helper->debug('🔍 getConstituentEmails: Object items format', ['count' => count($data->items)]);
                        return $data->items;
                    }
                    // Single email object
                    if (isset($data->address)) {
                        $helper->debug('🔍 getConstituentEmails: Single email object');
                        return [$data];
                    }
                }
                
                // Fallback to extraction method
                $emails = $this->extractConstituentsFromResponse($data);
                $helper->debug('🔍 getConstituentEmails: Extracted via fallback', [
                    'count' => count($emails),
                    'response_format' => gettype($data),
                    'raw_data_sample' => is_array($data) && !empty($data) ? array_slice($data, 0, 1) : $data
                ]);
                return $emails;
            } else {
                $helper->debug('❌ getConstituentEmails: Request failed', [
                    'lgl_id' => $lgl_id,
                    'success' => $response['success'] ?? false,
                    'error' => $response['error'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            $helper->debug('❌ getConstituentEmails: Error fetching emails', [
                'lgl_id' => $lgl_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        return [];
    }
    
    /**
     * Add phone number to existing constituent
     * (Multi-request pattern - matching legacy lgl_add_object)
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $phone_data Phone data array
     * @return array API response
     */
    public function addPhoneNumber(string $lgl_id, array $phone_data): array {
        $endpoint = "constituents/{$lgl_id}/phone_numbers";
        return $this->makeRequest($endpoint, 'POST', $phone_data, false);
    }
    
    /**
     * Safely add phone number - checks for duplicates first
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $phone_data Phone data array
     * @return array API response with 'skipped' flag if duplicate found
     */
    public function addPhoneNumberSafe(string $lgl_id, array $phone_data): array {
        $helper = Helper::getInstance();
        $phone_number = $this->normalizePhoneNumber($phone_data['number'] ?? '');
        
        if (empty($phone_number)) {
            return ['success' => false, 'error' => 'No phone number provided'];
        }
        
        $helper->debug('🔍 addPhoneNumberSafe: Checking for duplicate', [
            'lgl_id' => $lgl_id,
            'phone' => $phone_number
        ]);
        
        // Check if phone already exists
        $existing_phones = $this->getConstituentPhones($lgl_id);
        $helper->debug('🔍 addPhoneNumberSafe: Found existing phones', [
            'count' => count($existing_phones),
            'phones' => array_map(function($p) {
                return is_array($p) ? ($p['number'] ?? 'unknown') : ($p->number ?? 'unknown');
            }, $existing_phones)
        ]);
        
        foreach ($existing_phones as $existing_phone) {
            // Handle both array and object formats
            $existing_number_raw = '';
            if (is_array($existing_phone)) {
                $existing_number_raw = $existing_phone['number'] ?? '';
            } elseif (is_object($existing_phone)) {
                $existing_number_raw = $existing_phone->number ?? '';
            }
            
            $existing_number = $this->normalizePhoneNumber($existing_number_raw);
            if ($existing_number && $existing_number === $phone_number) {
                $helper->debug('⚠️ Phone already exists, skipping duplicate', [
                    'lgl_id' => $lgl_id,
                    'phone' => $phone_number,
                    'existing_phone_id' => is_array($existing_phone) ? ($existing_phone['id'] ?? null) : ($existing_phone->id ?? null)
                ]);
                return ['success' => true, 'skipped' => true, 'message' => 'Phone already exists'];
            }
        }
        
        // Phone doesn't exist, add it
        $helper->debug('✅ addPhoneNumberSafe: Phone is new, adding', ['phone' => $phone_number]);
        return $this->addPhoneNumber($lgl_id, $phone_data);
    }
    
    /**
     * Update or add phone number - updates if exists and different, adds if new
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $phone_data Phone data array
     * @return array API response with 'updated', 'added', or 'skipped' flag
     */
    public function updateOrAddPhoneNumber(string $lgl_id, array $phone_data): array {
        $helper = Helper::getInstance();
        $phone_number = $this->normalizePhoneNumber($phone_data['number'] ?? '');
        
        if (empty($phone_number)) {
            return ['success' => false, 'error' => 'No phone number provided'];
        }
        
        $helper->debug('🔄 updateOrAddPhoneNumber: Checking existing phones', [
            'lgl_id' => $lgl_id,
            'phone' => $phone_number
        ]);
        
        // Check if phone already exists
        $existing_phones = $this->getConstituentPhones($lgl_id);
        $exact_match_found = false;
        
        foreach ($existing_phones as $existing_phone) {
            $existing_number_raw = '';
            $existing_id = null;
            
            if (is_array($existing_phone)) {
                $existing_number_raw = $existing_phone['number'] ?? '';
                $existing_id = $existing_phone['id'] ?? null;
            } elseif (is_object($existing_phone)) {
                $existing_number_raw = $existing_phone->number ?? '';
                $existing_id = $existing_phone->id ?? null;
            }
            
            $existing_number = $this->normalizePhoneNumber($existing_number_raw);
            
            if ($existing_number && $existing_number === $phone_number) {
                $exact_match_found = true;
                // Phone exists - check if it needs updating
                $needs_update = false;
                if (is_array($existing_phone)) {
                    $existing_type = $existing_phone['phone_number_type_id'] ?? null;
                    $new_type = $phone_data['phone_number_type_id'] ?? null;
                    $existing_preferred = !empty($existing_phone['is_preferred']);
                    $new_preferred = !empty($phone_data['is_preferred']);
                    if ($existing_type != $new_type || $existing_preferred != $new_preferred) {
                        $needs_update = true;
                    }
                }
                
                if ($needs_update && $existing_id) {
                    $helper->debug('🔄 updateOrAddPhoneNumber: Updating existing phone', [
                        'phone_id' => $existing_id,
                        'phone' => $phone_number
                    ]);
                    $result = $this->updatePhoneNumber($lgl_id, $existing_id, $phone_data);
                    return array_merge($result, ['updated' => true]);
                } else {
                    $helper->debug('✅ updateOrAddPhoneNumber: Phone unchanged, skipping', [
                        'phone' => $phone_number
                    ]);
                    return ['success' => true, 'skipped' => true, 'message' => 'Phone unchanged'];
                }
            }
        }
        
        // If phone number is different but we have existing phones, update the preferred one
        if (!$exact_match_found && !empty($existing_phones)) {
            // Find preferred phone to replace
            $preferred_phone_id = null;
            foreach ($existing_phones as $check_phone) {
                $is_preferred = false;
                $check_id = null;
                if (is_array($check_phone)) {
                    $is_preferred = !empty($check_phone['is_preferred']);
                    $check_id = $check_phone['id'] ?? null;
                } elseif (is_object($check_phone)) {
                    $is_preferred = !empty($check_phone->is_preferred);
                    $check_id = $check_phone->id ?? null;
                }
                if ($is_preferred && $check_id) {
                    $preferred_phone_id = $check_id;
                    break;
                }
            }
            
            // If no preferred found, use first one
            if (!$preferred_phone_id && !empty($existing_phones[0])) {
                $first_phone = $existing_phones[0];
                $preferred_phone_id = is_array($first_phone) ? ($first_phone['id'] ?? null) : ($first_phone->id ?? null);
            }
            
            if ($preferred_phone_id) {
                $helper->debug('🔄 updateOrAddPhoneNumber: Updating existing phone (replacing preferred/first)', [
                    'phone_id' => $preferred_phone_id,
                    'new_phone' => $phone_number
                ]);
                $result = $this->updatePhoneNumber($lgl_id, $preferred_phone_id, $phone_data);
                return array_merge($result, ['updated' => true]);
            }
        }
        
        // Phone doesn't exist, add it
        $helper->debug('➕ updateOrAddPhoneNumber: Adding new phone', ['phone' => $phone_number]);
        $result = $this->addPhoneNumber($lgl_id, $phone_data);
        return array_merge($result, ['added' => true]);
    }
    
    /**
     * Get all phone numbers for a constituent
     * 
     * @param string $lgl_id LGL constituent ID
     * @return array Array of phone number records
     */
    private function getConstituentPhones(string $lgl_id): array {
        $helper = Helper::getInstance();
        try {
            $response = $this->makeRequest("constituents/{$lgl_id}/phone_numbers", 'GET', [], false);
            if ($response['success'] && isset($response['data'])) {
                $data = $response['data'];
                
                // Handle different response formats
                if (is_array($data)) {
                    // Check if it's a direct array of phone objects
                    if (!empty($data) && (isset($data[0]['number']) || (is_object($data[0]) && isset($data[0]->number)))) {
                        $helper->debug('🔍 getConstituentPhones: Direct array format', ['count' => count($data)]);
                        return $data;
                    }
                    // Check if it has 'items' key
                    if (isset($data['items']) && is_array($data['items'])) {
                        $helper->debug('🔍 getConstituentPhones: Items array format', ['count' => count($data['items'])]);
                        return $data['items'];
                    }
                } elseif (is_object($data)) {
                    // Object with items property
                    if (isset($data->items) && is_array($data->items)) {
                        $helper->debug('🔍 getConstituentPhones: Object items format', ['count' => count($data->items)]);
                        return $data->items;
                    }
                }
                
                // Fallback to extraction method
                $phones = $this->extractConstituentsFromResponse($data);
                $helper->debug('🔍 getConstituentPhones: Extracted via fallback', [
                    'count' => count($phones),
                    'response_format' => gettype($data)
                ]);
                return $phones;
            }
        } catch (\Exception $e) {
            $helper->debug('❌ getConstituentPhones: Error fetching phones', [
                'lgl_id' => $lgl_id,
                'error' => $e->getMessage()
            ]);
        }
        return [];
    }
    
    /**
     * Normalize phone number for comparison (remove formatting)
     * 
     * @param string $phone Phone number
     * @return string Normalized phone number
     */
    private function normalizePhoneNumber(string $phone): string {
        // Remove all non-digit characters
        return preg_replace('/\D/', '', $phone);
    }
    
    /**
     * Add street address to existing constituent
     * (Multi-request pattern - matching legacy lgl_add_object)
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $address_data Address data array
     * @return array API response
     */
    public function addStreetAddress(string $lgl_id, array $address_data): array {
        $endpoint = "constituents/{$lgl_id}/street_addresses";
        return $this->makeRequest($endpoint, 'POST', $address_data, false);
    }
    
    /**
     * Add membership to existing constituent
     * (Multi-request pattern - matching legacy lgl_add_object)
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $membership_data Membership data array
     * @return array API response
     */
    public function addMembership(string $lgl_id, array $membership_data): array {
        $endpoint = "constituents/{$lgl_id}/memberships";
        if (function_exists('lgl_log')) {
            lgl_log('LGL Membership Payload', [
                'endpoint' => $endpoint,
                'payload' => $membership_data
            ]);
        }

        $response = $this->makeRequest($endpoint, 'POST', $membership_data, false);

        if (function_exists('lgl_log')) {
            lgl_log('LGL Membership Response', $response);
        }

        return $response;
    }

    /**
     * Safely add street address - checks for duplicates first
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $address_data Address data array
     * @return array API response with 'skipped' flag if duplicate found
     */
    public function addStreetAddressSafe(string $lgl_id, array $address_data): array {
        $helper = Helper::getInstance();
        
        // Extract key address fields for comparison
        $street = trim($address_data['street'] ?? '');
        $city = trim($address_data['city'] ?? '');
        $postal_code = trim($address_data['postal_code'] ?? '');
        
        if (empty($street)) {
            return ['success' => false, 'error' => 'No street address provided'];
        }
        
        $helper->debug('🔍 addStreetAddressSafe: Checking for duplicate', [
            'lgl_id' => $lgl_id,
            'street' => $street,
            'city' => $city,
            'postal_code' => $postal_code
        ]);
        
        // Check if address already exists
        $existing_addresses = $this->getConstituentAddresses($lgl_id);
        $helper->debug('🔍 addStreetAddressSafe: Found existing addresses', [
            'count' => count($existing_addresses)
        ]);
        
        foreach ($existing_addresses as $existing_address) {
            $existing_street = '';
            $existing_city = '';
            $existing_postal = '';
            
            if (is_array($existing_address)) {
                $existing_street = trim($existing_address['street'] ?? '');
                $existing_city = trim($existing_address['city'] ?? '');
                $existing_postal = trim($existing_address['postal_code'] ?? '');
            } elseif (is_object($existing_address)) {
                $existing_street = trim($existing_address->street ?? '');
                $existing_city = trim($existing_address->city ?? '');
                $existing_postal = trim($existing_address->postal_code ?? '');
            }
            
            // Compare normalized addresses (street + city + postal code if both have it)
            $street_match = strcasecmp($existing_street, $street) === 0;
            $city_match = strcasecmp($existing_city, $city) === 0;
            $postal_match = true; // Default to true if either is empty
            if (!empty($postal_code) && !empty($existing_postal)) {
                $postal_match = strcasecmp($existing_postal, $postal_code) === 0;
            }
            
            if ($existing_street && $street_match && $city_match && $postal_match) {
                $helper->debug('⚠️ Address already exists, skipping duplicate', [
                    'lgl_id' => $lgl_id,
                    'street' => $street,
                    'address_id' => is_array($existing_address) ? ($existing_address['id'] ?? null) : ($existing_address->id ?? null)
                ]);
                return ['success' => true, 'skipped' => true, 'message' => 'Address already exists'];
            }
        }
        
        // Address doesn't exist, add it
        $helper->debug('✅ addStreetAddressSafe: Address is new, adding', ['street' => $street]);
        return $this->addStreetAddress($lgl_id, $address_data);
    }
    
    /**
     * Update or add street address - updates if exists and different, adds if new
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $address_data Address data array
     * @return array API response with 'updated', 'added', or 'skipped' flag
     */
    public function updateOrAddStreetAddress(string $lgl_id, array $address_data): array {
        $helper = Helper::getInstance();
        
        $street = trim($address_data['street'] ?? '');
        $city = trim($address_data['city'] ?? '');
        $postal_code = trim($address_data['postal_code'] ?? '');
        
        if (empty($street)) {
            return ['success' => false, 'error' => 'No street address provided'];
        }
        
        $helper->debug('🔄 updateOrAddStreetAddress: Checking existing addresses', [
            'lgl_id' => $lgl_id,
            'street' => $street,
            'city' => $city
        ]);
        
        // Check if address already exists
        $existing_addresses = $this->getConstituentAddresses($lgl_id);
        
        // Find preferred address or first address to update
        $address_to_update = null;
        $address_to_update_id = null;
        $exact_match_found = false;
        
        foreach ($existing_addresses as $existing_address) {
            $existing_street = '';
            $existing_city = '';
            $existing_postal = '';
            $existing_id = null;
            $is_preferred = false;
            
            if (is_array($existing_address)) {
                $existing_street = trim($existing_address['street'] ?? '');
                $existing_city = trim($existing_address['city'] ?? '');
                $existing_postal = trim($existing_address['postal_code'] ?? '');
                $existing_id = $existing_address['id'] ?? null;
                $is_preferred = !empty($existing_address['is_preferred']);
            } elseif (is_object($existing_address)) {
                $existing_street = trim($existing_address->street ?? '');
                $existing_city = trim($existing_address->city ?? '');
                $existing_postal = trim($existing_address->postal_code ?? '');
                $existing_id = $existing_address->id ?? null;
                $is_preferred = !empty($existing_address->is_preferred);
            }
            
            // Compare normalized addresses (street + city + postal code if both have it)
            $street_match = strcasecmp($existing_street, $street) === 0;
            $city_match = strcasecmp($existing_city, $city) === 0;
            $postal_match = true; // Default to true if either is empty
            if (!empty($postal_code) && !empty($existing_postal)) {
                $postal_match = strcasecmp($existing_postal, $postal_code) === 0;
            }
            
            // If exact match found, update it
            if ($existing_street && $street_match && $city_match && $postal_match) {
                $exact_match_found = true;
                $address_to_update = $existing_address;
                $address_to_update_id = $existing_id;
                break; // Found exact match, use this one
            }
            
            // Track preferred address or first address for potential update
            if (!$address_to_update && ($is_preferred || !$address_to_update)) {
                $address_to_update = $existing_address;
                $address_to_update_id = $existing_id;
            }
        }
        
        // If exact match found, check if it needs updating
        if ($exact_match_found && $address_to_update_id) {
            $needs_update = false;
            if (is_array($address_to_update)) {
                $existing_street_from_update = trim($address_to_update['street'] ?? '');
                $existing_state = trim($address_to_update['state'] ?? '');
                $new_state = trim($address_data['state'] ?? '');
                $existing_type = $address_to_update['street_address_type_id'] ?? null;
                $new_type = $address_data['street_address_type_id'] ?? null;
                $existing_preferred = !empty($address_to_update['is_preferred']);
                $new_preferred = !empty($address_data['is_preferred']);
                
                if ($existing_street_from_update !== $street || 
                    $existing_state !== $new_state || 
                    $existing_type != $new_type || 
                    $existing_preferred != $new_preferred) {
                    $needs_update = true;
                }
            }
            
            if ($needs_update) {
                $helper->debug('🔄 updateOrAddStreetAddress: Updating existing address (exact match)', [
                    'address_id' => $address_to_update_id,
                    'old_street' => is_array($address_to_update) ? ($address_to_update['street'] ?? '') : ($address_to_update->street ?? ''),
                    'new_street' => $street,
                    'address_data' => $address_data
                ]);
                $result = $this->updateStreetAddress($lgl_id, $address_to_update_id, $address_data);
                $helper->debug('📥 updateOrAddStreetAddress: Update API response', [
                    'address_id' => $address_to_update_id,
                    'success' => $result['success'] ?? false,
                    'http_code' => $result['http_code'] ?? null,
                    'error' => $result['error'] ?? null,
                    'response_data' => $result['data'] ?? null
                ]);
                if (!empty($result['success'])) {
                    return array_merge($result, ['updated' => true]);
                } else {
                    $helper->debug('❌ updateOrAddStreetAddress: Update failed, will try adding instead', [
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                    // If update failed, try adding as new
                    $result = $this->addStreetAddress($lgl_id, $address_data);
                    return array_merge($result, ['added' => true, 'update_failed' => true]);
                }
            } else {
                $helper->debug('✅ updateOrAddStreetAddress: Address unchanged, skipping', [
                    'street' => $street
                ]);
                return ['success' => true, 'skipped' => true, 'message' => 'Address unchanged'];
            }
        }
        
        // No exact match - update preferred address or first address if exists
        if ($address_to_update_id) {
            $helper->debug('🔄 updateOrAddStreetAddress: Updating existing address (replacing preferred/first)', [
                'address_id' => $address_to_update_id,
                'old_street' => is_array($address_to_update) ? ($address_to_update['street'] ?? '') : ($address_to_update->street ?? ''),
                'new_street' => $street,
                'address_data' => $address_data
            ]);
            $result = $this->updateStreetAddress($lgl_id, $address_to_update_id, $address_data);
            $helper->debug('📥 updateOrAddStreetAddress: Update API response', [
                'address_id' => $address_to_update_id,
                'success' => $result['success'] ?? false,
                'http_code' => $result['http_code'] ?? null,
                'error' => $result['error'] ?? null,
                'response_data' => $result['data'] ?? null
            ]);
            if (!empty($result['success'])) {
                return array_merge($result, ['updated' => true]);
            } else {
                $helper->debug('❌ updateOrAddStreetAddress: Update failed, will try adding instead', [
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
                // If update failed, try adding as new
                $result = $this->addStreetAddress($lgl_id, $address_data);
                return array_merge($result, ['added' => true, 'update_failed' => true]);
            }
        }
        
        // No existing address, add it
        $helper->debug('➕ updateOrAddStreetAddress: Adding new address', ['street' => $street]);
        $result = $this->addStreetAddress($lgl_id, $address_data);
        return array_merge($result, ['added' => true]);
    }

    /**
     * Get all street addresses for a constituent
     * 
     * @param string $lgl_id LGL constituent ID
     * @return array Array of address records
     */
    private function getConstituentAddresses(string $lgl_id): array {
        $helper = Helper::getInstance();
        try {
            $response = $this->makeRequest("constituents/{$lgl_id}/street_addresses", 'GET', [], false);
            if ($response['success'] && isset($response['data'])) {
                $data = $response['data'];
                
                // Handle different response formats (same as emails/phones)
                if (is_array($data)) {
                    // Check if it's a direct array of address objects
                    if (!empty($data) && (isset($data[0]['street']) || (is_object($data[0]) && isset($data[0]->street)))) {
                        $helper->debug('🔍 getConstituentAddresses: Direct array format', ['count' => count($data)]);
                        return $data;
                    }
                    // Check if it has 'items' key
                    if (isset($data['items']) && is_array($data['items'])) {
                        $helper->debug('🔍 getConstituentAddresses: Items array format', ['count' => count($data['items'])]);
                        return $data['items'];
                    }
                } elseif (is_object($data)) {
                    // Object with items property
                    if (isset($data->items) && is_array($data->items)) {
                        $helper->debug('🔍 getConstituentAddresses: Object items format', ['count' => count($data->items)]);
                        return $data->items;
                    }
                }
                
                // Fallback to extraction method
                $addresses = $this->extractConstituentsFromResponse($data);
                $helper->debug('🔍 getConstituentAddresses: Extracted via fallback', [
                    'count' => count($addresses),
                    'response_format' => gettype($data)
                ]);
                return $addresses;
            }
        } catch (\Exception $e) {
            $helper->debug('❌ getConstituentAddresses: Error fetching addresses', [
                'lgl_id' => $lgl_id,
                'error' => $e->getMessage()
            ]);
        }
        return [];
    }
}

// Maintain backward compatibility
if (!function_exists('lgl_connect')) {
    function lgl_connect(): Connection {
        return Connection::getInstance();
    }
}
