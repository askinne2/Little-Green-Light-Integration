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
        $this->initializeConnection();
    }
    
    /**
     * Initialize connection settings
     */
    private function initializeConnection(): void {
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
            // Generate cache key for GET requests
            $cache_key = null;
            if ($method === 'GET' && $use_cache) {
                $cache_key = 'api_request_' . md5($endpoint . serialize($data));
                $cached_response = CacheManager::get($cache_key);
                
                if ($cached_response !== false) {
                    error_log('LGL Connection: Cache HIT for ' . $endpoint);
                    return $cached_response;
                }
            }
            
            // Build request URI
            $this->requestUri = $this->buildRequestUri($endpoint);
            
            // Prepare request arguments
            $request_args = $this->prepareRequestArgs($method, $data);
            
            // Log request for debugging
            $this->logRequest($endpoint, $method, $data);
            
            // Make HTTP request
            $response = $this->executeHttpRequest($method, $request_args);
            
            // Process response
            $processed_response = $this->processResponse($response);
            
            // Cache successful GET responses
            if ($method === 'GET' && $use_cache && $cache_key && $this->isSuccessfulResponse($processed_response)) {
                CacheManager::set($cache_key, $processed_response, 3600); // 1 hour cache
            }
            
            // Log response for debugging
            $this->logResponse($endpoint, $processed_response);
            
            return $processed_response;
            
        } catch (\Exception $e) {
            error_log('LGL Connection Error: ' . $e->getMessage());
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
                // Add data as JSON body for other methods
                $args['body'] = json_encode($data);
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
        
        error_log(sprintf(
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
        
        error_log(sprintf(
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
     * Create relationship between constituents
     * 
     * @param array $relationship_data Relationship data
     * @return array API response
     */
    public function createRelationship(array $relationship_data): array {
        return $this->makeRequest('relationships', 'POST', $relationship_data, false);
    }
    
    /**
     * Get relationships for constituent
     * 
     * @param string $constituent_id LGL constituent ID
     * @return array API response
     */
    public function getRelationships(string $constituent_id): array {
        return $this->makeRequest("constituents/{$constituent_id}/relationships");
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
                error_log('LGL Connection: Failed to get membership levels: ' . $e->getMessage());
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
    public function searchByName(string $name, string $email) {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $helper->debug('🔍 Connection::searchByName() STARTED', [
            'name' => $name,
            'email' => $email
        ]);
        
        try {
            // Clean up the name (remove URL encoding)
            $clean_name = str_replace('%20', ' ', $name);
            
            // Try different search approaches
            $search_methods = [
                // Method 1: Search by email first (most reliable)
                ['endpoint' => 'constituents', 'params' => ['email' => $email]],
                // Method 2: Search by name
                ['endpoint' => 'constituents', 'params' => ['search' => $clean_name]],
                // Method 3: Search with q parameter
                ['endpoint' => 'constituents', 'params' => ['q' => $clean_name]]
            ];
            
            foreach ($search_methods as $method_index => $method) {
                $helper->debug("🔍 Trying search method " . ($method_index + 1), [
                    'endpoint' => $method['endpoint'],
                    'params' => $method['params']
                ]);
                
                $response = $this->makeRequest($method['endpoint'], 'GET', $method['params'], false);
                
                $helper->debug("🔍 Search method " . ($method_index + 1) . " response", [
                    'success' => $response['success'] ?? false,
                    'http_code' => $response['http_code'] ?? 'unknown',
                    'has_data' => isset($response['data']),
                    'data_type' => isset($response['data']) ? gettype($response['data']) : 'none'
                ]);
                
                if ($response['success'] && isset($response['data'])) {
                    $constituents = $this->extractConstituentsFromResponse($response['data']);
                    
                    if (!empty($constituents)) {
                        $helper->debug("✅ Found " . count($constituents) . " constituents with method " . ($method_index + 1));
                        
                        // For email search, return first match (most reliable)
                        if ($method_index === 0 && !empty($constituents)) {
                            $constituent = $constituents[0];
                            
                            // Debug the constituent structure
                            $helper->debug('🔍 Examining first constituent structure', [
                                'constituent_type' => gettype($constituent),
                                'constituent_keys' => is_object($constituent) ? array_keys((array)$constituent) : (is_array($constituent) ? array_keys($constituent) : 'not array/object'),
                                'has_id_property' => is_object($constituent) ? isset($constituent->id) : isset($constituent['id']),
                                'id_value' => is_object($constituent) ? ($constituent->id ?? 'not set') : ($constituent['id'] ?? 'not set')
                            ]);
                            
                            $lgl_id = is_object($constituent) ? ($constituent->id ?? null) : ($constituent['id'] ?? null);
                            
                            if ($lgl_id) {
                                $helper->debug('✅ Email search match found', ['lgl_id' => $lgl_id]);
                                return $lgl_id;
                            } else {
                                $helper->debug('❌ Email search found constituent but no ID', [
                                    'constituent_data' => $constituent
                                ]);
                            }
                        }
                        
                        // For name searches, verify email match
                        foreach ($constituents as $constituent) {
                            if ($this->verifyConstituentEmail($constituent, $email)) {
                                $lgl_id = is_object($constituent) ? $constituent->id : $constituent['id'];
                                $helper->debug('✅ Name + email match confirmed', ['lgl_id' => $lgl_id]);
                                return $lgl_id;
                            }
                        }
                    }
                }
            }
            
            $helper->debug('❌ No matching constituent found with any search method');
            return false;
            
        } catch (\Exception $e) {
            $helper->debug('❌ Connection::searchByName() - Exception', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
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
        } catch (Exception $e) {
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
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $helper->debug('🔍 extractConstituentsFromResponse() - Raw data analysis', [
            'data_type' => gettype($data),
            'data_keys' => is_object($data) ? array_keys((array)$data) : (is_array($data) ? array_keys($data) : 'not array/object'),
            'data_sample' => is_array($data) ? (count($data) > 0 ? $data[0] : 'empty array') : (is_object($data) ? 'object' : $data)
        ]);
        
        // Handle different response formats
        if (is_array($data)) {
            // Check if it has an 'items' key (LGL API format)
            if (isset($data['items']) && is_array($data['items'])) {
                $helper->debug('✅ Found LGL API format with items array', ['count' => count($data['items'])]);
                return $data['items'];
            }
            // Check if it's a direct array of constituents
            if (!empty($data) && (isset($data[0]['id']) || (is_object($data[0]) && isset($data[0]->id)))) {
                $helper->debug('✅ Found direct array of constituents', ['count' => count($data)]);
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
                $helper->debug('✅ Extracted constituents from array', ['count' => count($constituents)]);
                return $constituents;
            }
        } elseif (is_object($data)) {
            // Object with items property
            if (isset($data->items) && is_array($data->items)) {
                $helper->debug('✅ Found object with items property', ['count' => count($data->items)]);
                return $data->items;
            }
            // Single constituent object
            if (isset($data->id)) {
                $helper->debug('✅ Found single constituent object', ['id' => $data->id]);
                return [$data];
            }
            // Convert object to array and check again
            $data_array = (array)$data;
            if (isset($data_array['items']) && is_array($data_array['items'])) {
                $helper->debug('✅ Found items in converted array', ['count' => count($data_array['items'])]);
                return $data_array['items'];
            }
        }
        
        $helper->debug('⚠️ Unknown response format in extractConstituentsFromResponse', [
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
        
        $helper->debug('📧 Verifying email for constituent', [
            'constituent_id' => $constituent_id,
            'target_email' => $email
        ]);
        
        try {
            // Get email addresses for this constituent
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
            
            $helper->debug('❌ No email match for constituent', [
                'constituent_id' => $constituent_id
            ]);
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
     * Update membership in LGL
     * 
     * @param string $lgl_id LGL constituent ID
     * @param string $membership_id Membership ID
     * @param array $membership_data Membership data
     * @return bool Success status
     */
    public function updateMembership(string $lgl_id, string $membership_id, array $membership_data): bool {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $helper->debug('🔄 Connection::updateMembership()', [
            'lgl_id' => $lgl_id,
            'membership_id' => $membership_id,
            'membership_data' => $membership_data
        ]);
        
        try {
            $response = $this->makeRequest(
                "constituents/{$lgl_id}/memberships/{$membership_id}",
                'PUT',
                $membership_data,
                false
            );
            
            if ($response['success']) {
                $helper->debug('✅ Membership updated successfully', [
                    'membership_id' => $membership_id
                ]);
                return true;
            } else {
                $helper->debug('❌ Failed to update membership', $response);
                return false;
            }
            
        } catch (\Exception $e) {
            $helper->debug('❌ Error updating membership', [
                'lgl_id' => $lgl_id,
                'membership_id' => $membership_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Add membership to LGL constituent
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $membership_data Membership data
     * @return string|false Membership ID on success, false on failure
     */
    public function addMembership(string $lgl_id, array $membership_data) {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        $helper->debug('➕ Connection::addMembership()', [
            'lgl_id' => $lgl_id,
            'membership_data' => $membership_data
        ]);
        
        try {
            $response = $this->makeRequest(
                "constituents/{$lgl_id}/memberships",
                'POST',
                $membership_data,
                false
            );
            
            if ($response['success'] && isset($response['data']['id'])) {
                $membership_id = $response['data']['id'];
                $helper->debug('✅ Membership added successfully', [
                    'membership_id' => $membership_id
                ]);
                return $membership_id;
            } else {
                $helper->debug('❌ Failed to add membership', $response);
                return false;
            }
            
        } catch (\Exception $e) {
            $helper->debug('❌ Error adding membership', [
                'lgl_id' => $lgl_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
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
}

// Maintain backward compatibility
if (!function_exists('lgl_connect')) {
    function lgl_connect(): Connection {
        return Connection::getInstance();
    }
}
