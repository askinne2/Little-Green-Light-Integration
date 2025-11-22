<?php
/**
 * LGL Payments Manager
 * 
 * Manages payment processing, gift tracking, and fundraising data in Little Green Light.
 * Handles memberships, class registrations, event payments, and donations.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

use UpstateInternational\LGL\Core\CacheManager;

/**
 * Payments Class
 * 
 * Manages LGL payment processing and gift tracking
 */
class Payments {
    
    /**
     * Class instance
     * 
     * @var Payments|null
     */
    private static $instance = null;
    
    /**
     * Payment data
     * 
     * @var array
     */
    private $paymentData = [];
    
    /**
     * LGL fundraising data
     * 
     * @var array
     */
    private $lglFundraising = [];
    
    /**
     * Payment information
     * 
     * @var array
     */
    private $payment = [];
    
    /**
     * Gift information
     * 
     * @var array
     */
    private $gift = [];
    
    /**
     * Category information
     * 
     * @var array
     */
    private $category = [];
    
    /**
     * Campaign information
     * 
     * @var array
     */
    private $campaign = [];
    
    /**
     * Fund information
     * 
     * @var array
     */
    private $fund = [];
    
    /**
     * API Settings instance
     * 
     * @var ApiSettings
     */
    private $lgl;
    
    /**
     * Payment types cache
     * 
     * @var array
     */
    private $paymentTypesCache = [];
    
    /**
     * Get instance
     * 
     * @return Payments
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
        $this->initializePayments();
    }
    
    /**
     * Initialize payments system
     */
    private function initializePayments(): void {
        $this->resetPaymentData();
        // error_log('LGL Payments: Initialized successfully');
    }
    
    /**
     * Reset all payment data
     */
    private function resetPaymentData(): void {
        $this->paymentData = [];
        $this->lglFundraising = [];
        $this->payment = [];
        $this->gift = [];
        $this->category = [];
        $this->campaign = [];
        $this->fund = [];
    }
    
    /**
     * Find LGL object key in list
     * 
     * @param string $item_name_to_find Item to find
     * @param string $list_column_name Column to search in
     * @param array $list List to search
     * @return string|null Found key or null
     */
    public function findLglObjectKey(string $item_name_to_find, string $list_column_name, array $list): ?string {
        if (empty($list) || !is_array($list)) {
            return null;
        }
        
        foreach ($list as $item) {
            if (isset($item[$list_column_name]) && 
                strtolower($item[$list_column_name]) === strtolower($item_name_to_find)) {
                return $item['id'] ?? null;
            }
        }
        
        return null;
    }
    
    /**
     * Retrieve payment types from LGL
     * 
     * @param string $method Payment method
     * @param string|null $fund Fund name
     * @param string|null $payment_type Payment type
     * @param string|null $lgl_class_id LGL class ID
     * @param string|null $payment_gateway Payment gateway
     * @return array Payment types data
     */
    public function retrievePaymentTypes(
        string $method = 'Memberships', 
        ?string $fund = null, 
        ?string $payment_type = null, 
        ?string $lgl_class_id = null, 
        ?string $payment_gateway = null
    ): array {
        try {
            $helper = Helper::getInstance();
            $helper->debug('🔍 LGL Payments: retrievePaymentTypes called', ['method' => $method]);
            
            // Generate cache key
            $cache_key = 'payment_types_' . md5($method . ($fund ?? '') . ($payment_type ?? '') . ($lgl_class_id ?? '') . ($payment_gateway ?? ''));
            
            // Check cache first
            $cached_data = CacheManager::get($cache_key);
            if ($cached_data !== false) {
                $helper->debug('🔍 LGL Payments: Using cached data');
                return $cached_data;
            }
            
            $helper->debug('🔍 LGL Payments: No cache, making API requests...');
            
            $connection = Connection::getInstance();
            
            // Get payment types from API (match legacy: payment_types.json)
            $payment_types_response = $connection->makeRequest('payment_types.json', 'GET');
            
            if (!$payment_types_response['success']) {
                $this->debug('Failed to retrieve payment types', $payment_types_response['error'] ?? 'Unknown error');
                return [];
            }
            
            $payment_types = $payment_types_response['data'] ?? [];
            
            // Get funds
            $funds_response = $connection->makeRequest('funds.json', 'GET');
            $funds = $funds_response['success'] ? ($funds_response['data'] ?? []) : [];
            
            // Get campaigns
            $campaigns_response = $connection->makeRequest('campaigns.json', 'GET');
            $campaigns = $campaigns_response['success'] ? ($campaigns_response['data'] ?? []) : [];
            
            // Get gift types (missing from modern method)
            $gift_types_response = $connection->makeRequest('gift_types.json', 'GET');
            $gift_types = $gift_types_response['success'] ? ($gift_types_response['data'] ?? []) : [];
            
            // Get categories
            $categories_response = $connection->makeRequest('gift_categories.json', 'GET');
            $categories = $categories_response['success'] ? ($categories_response['data'] ?? []) : [];
            
            $result = [
                'payment_types' => $payment_types,
                'gift_types' => $gift_types,
                'funds' => $funds,
                'campaigns' => $campaigns,
                'categories' => $categories,
                'method' => $method,
                'retrieved_at' => current_time('mysql')
            ];
            
            // Cache for 30 minutes
            CacheManager::set($cache_key, $result, 1800);
            
            $this->debug('Payment types retrieved', [
                'method' => $method,
                'count' => count($payment_types)
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug('Exception retrieving payment types', $e->getMessage());
            return [];
        }
    }
    
    /**
     * Setup membership payment
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $order_id WooCommerce order ID
     * @param float $price Payment amount
     * @param string $date Payment date
     * @param string|null $payment_type Payment type override
     * @return array Payment setup result
     */
    public function setupMembershipPayment(
        string $lgl_id, 
        $external_id, 
        float $price, 
        string $date, 
        ?string $payment_type = null,
        $product = null
    ): array {
        // external_id can be int (order_id) or string (order_id-product_id)
        $order_id = is_int($external_id) ? $external_id : (int) explode('-', (string) $external_id)[0];
        try {
            // Get order details
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    throw new \Exception('Order not found: ' . $order_id);
                }
            } else {
                throw new \Exception('WooCommerce not available');
            }
            
            // Get payment types for memberships
            $payment_data = $this->retrievePaymentTypes('Memberships');
            
            $this->debug('Payment data retrieved', [
                'method' => 'Memberships',
                'data_keys' => array_keys($payment_data),
                'funds_count' => count($payment_data['funds'] ?? []),
                'campaigns_count' => count($payment_data['campaigns'] ?? []),
                'payment_types_count' => count($payment_data['payment_types'] ?? [])
            ]);
            
            if (empty($payment_data)) {
                throw new \Exception('No payment types available for memberships');
            }
            
            // Determine payment method from order
            $payment_method = $order->get_payment_method();
            $gateway_title = $order->get_payment_method_title();
            
            // CRITICAL: Access the 'items' arrays within LGL API response structure
            $funds_items = $payment_data['funds']['items'] ?? [];
            $campaigns_items = $payment_data['campaigns']['items'] ?? [];
            $categories_items = $payment_data['categories']['items'] ?? [];
            $gift_types_items = $payment_data['gift_types']['items'] ?? [];
            $payment_types_items = $payment_data['payment_types']['items'] ?? [];
            
            // Get fund ID from settings based on product category
            // CRITICAL: Pass the specific product being processed to ensure correct fund ID
            $fund_id = $this->getFundIdByProductCategory($order, $product);
            $fund_name = ''; // Empty - LGL resolves it
            
            // Get campaign ID from settings (preferred) or fallback to name lookup
            $settingsManager = null;
            if (function_exists('lgl_get_container')) {
                try {
                    $container = lgl_get_container();
                    if ($container->has('admin.settings_manager')) {
                        $settingsManager = $container->get('admin.settings_manager');
                    }
                } catch (\Exception $e) {
                    // SettingsManager not available, will use fallback
                }
            }
            
            $campaign_id = null;
            if ($settingsManager) {
                $campaign_id = $settingsManager->get('campaign_id_membership');
            }
            
            // Fallback to name lookup for backward compatibility
            if (empty($campaign_id)) {
                $campaign_name = 'Membership';
                $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
                if (!$campaign_id) {
                    // Legacy fallback
                    $campaign_name = 'Membership Fees';
                    $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
                }
            } else {
                $campaign_name = 'Membership'; // Use synced campaign name
            }
            
            // Find gift category - use "Memberships" under "Other Income" gift type
            // Based on actual LGL structure: Other Income → Memberships
            $category_name = 'Memberships';
            $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            
            // Fallback to "Misc." if "Memberships" not found (both under Other Income)
            if (empty($category_id)) {
                $category_name = 'Misc.';
                $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            }
            
            // Find gift type (match legacy: 'Other Income')
            $gift_type_name = 'Other Income';
            $gift_type_id = $this->findLglObjectKey($gift_type_name, 'name', $gift_types_items);
            
            // Find payment type
            $type_name = $payment_type ?: $this->determinePaymentType($payment_method);
            $type_id = $this->findLglObjectKey($type_name, 'name', $payment_types_items);
            
            // Build payment data (match legacy format)
            // LGL API requires all amounts to be formatted with decimals
            $formatted_amount = number_format((float)$price, 2, '.', '');
            
            // Generate descriptive payment note based on product type
            $payment_note = $this->generateMembershipPaymentNote($order, $order_id, $product);
            
            $payment_data_to_send = [
                'external_id' => $external_id,
                'is_anon' => false,
                'gift_type_id' => $gift_type_id,
                'gift_type_name' => $gift_type_name,
                'gift_category_id' => $category_id,
                'gift_category_name' => $category_name,
                'campaign_id' => $campaign_id,
                'campaign_name' => $campaign_name,
                'fund_id' => $fund_id,
                'fund_name' => $fund_name,
                'appeal_id' => 0,
                'appeal_name' => '',
                'event_id' => 0,
                'event_name' => '',
                'received_amount' => $formatted_amount,
                'received_date' => $this->formatDateForApi($date),
                'payment_type_id' => $type_id,
                'payment_type_name' => $type_name,
                'check_number' => '',
                'deductible_amount' => $formatted_amount,
                'note' => $payment_note,
                'ack_template_name' => '',
                'deposit_date' => $this->formatDateForApi($date),
                'deposited_amount' => $formatted_amount,
                'parent_gift_id' => 0,
                'parent_external_id' => 0,
                'team_member' => ''
            ];
            
            // Create payment in LGL
            $connection = Connection::getInstance();
            $result = $connection->addPayment($lgl_id, $payment_data_to_send);
            
            if ($result['success']) {
                $this->debug('Membership payment created', [
                    'lgl_id' => $lgl_id,
                    'order_id' => $order_id,
                    'amount' => $price
                ]);
                
                // Cache payment data
                if (isset($result['data']['id'])) {
                    CacheManager::cachePayment($result['data']['id'], $result['data']);
                }
            } else {
                $this->debug('Failed to create membership payment', $result['error'] ?? 'Unknown error');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug('Exception in setupMembershipPayment', $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Setup class registration payment
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $order_id WooCommerce order ID
     * @param float $price Payment amount
     * @param string $date Payment date
     * @param string $class_type Class type
     * @param string $event_name Class name for event tracking (product name)
     * @return array Payment setup result
     */
    public function setupClassPayment(
        string $lgl_id, 
        int $order_id, 
        float $price, 
        string $date, 
        string $class_type, 
        string $event_name
    ): array {
        try {
            // Get order details
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    throw new \Exception('Order not found: ' . $order_id);
                }
            } else {
                throw new \Exception('WooCommerce not available');
            }
            
            // Get payment types for classes
            $payment_data = $this->retrievePaymentTypes('Classes');
            
            if (empty($payment_data)) {
                throw new \Exception('No payment types available for classes');
            }
            
            // Determine payment method
            $payment_method = $order->get_payment_method();
            $gateway_title = $order->get_payment_method_title();
            
            // Find payment type
            // CRITICAL: Access the 'items' array within payment_types (LGL API response structure)
            $payment_types_items = $payment_data['payment_types']['items'] ?? [];
            $type_name = $this->determinePaymentType($payment_method);
            $type_id = $this->findLglObjectKey($type_name, 'name', $payment_types_items);
            
            // Fallback: If payment type not found, use first available type
            if (empty($type_id) && !empty($payment_types_items)) {
                $first_type = reset($payment_types_items);
                if ($first_type && isset($first_type['id'])) {
                    $type_id = $first_type['id'];
                    $type_name = $first_type['name'] ?? $type_name;
                    $this->debug('⚠️ Payment type not found, using first available', [
                        'requested' => $this->determinePaymentType($payment_method),
                        'using_id' => $type_id,
                        'using_name' => $type_name
                    ]);
                }
            }
            
            // Get fund ID from settings based on product category
            $fund_id = $this->getFundIdByProductCategory($order);
            
            // Get gift type and category for classes (match legacy structure)
            $gift_types_items = $payment_data['gift_types']['items'] ?? [];
            $categories_items = $payment_data['categories']['items'] ?? [];
            $campaigns_items = $payment_data['campaigns']['items'] ?? [];
            
            $gift_type_name = 'Other Income';
            $gift_type_id = $this->findLglObjectKey($gift_type_name, 'name', $gift_types_items);
            
            // Find gift category - use "Language Classes" under "Other Income" gift type
            // Based on actual LGL structure: Other Income → Language Classes
            $category_name = 'Language Classes';
            $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            
            // Fallback to "Misc." if "Language Classes" not found (both under Other Income)
            if (empty($category_id)) {
                $category_name = 'Misc.';
                $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            }
            
            // Get campaign ID from settings (preferred) or fallback to name lookup
            $settingsManager = null;
            if (function_exists('lgl_get_container')) {
                try {
                    $container = lgl_get_container();
                    if ($container->has('admin.settings_manager')) {
                        $settingsManager = $container->get('admin.settings_manager');
                    }
                } catch (\Exception $e) {
                    // SettingsManager not available, will use fallback
                }
            }
            
            $campaign_id = null;
            if ($settingsManager) {
                $campaign_id = $settingsManager->get('campaign_id_language_classes');
            }
            
            // Fallback to name lookup for backward compatibility
            if (empty($campaign_id)) {
                $campaign_name = 'Language Programs';
                $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
                if (!$campaign_id) {
                    // Legacy fallback
                    $campaign_name = 'Language Class';
                    $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
                }
            } else {
                $campaign_name = 'Language Programs'; // Use synced campaign name
            }
            
            // LGL API requires all amounts to be formatted with decimals
            $formatted_amount = number_format((float)$price, 2, '.', '');
            
            // Build payment data (MUST match membership structure for LGL gifts endpoint)
            $payment_data_to_send = [
                'external_id' => $order_id,
                'is_anon' => false,
                'gift_type_id' => $gift_type_id,
                'gift_type_name' => $gift_type_name,
                'gift_category_id' => $category_id,
                'gift_category_name' => $category_name,
                'campaign_id' => $campaign_id,
                'campaign_name' => $campaign_name,
                'fund_id' => $fund_id,
                'fund_name' => '',
                'appeal_id' => 0,
                'appeal_name' => '',
                'event_id' => 0,
                'event_name' => $event_name, // Class name for event tracking
                'received_amount' => $formatted_amount,
                'received_date' => $this->formatDateForApi($date),
                'payment_type_id' => $type_id,
                'payment_type_name' => $type_name,
                'check_number' => '',
                'deductible_amount' => $formatted_amount,
                'note' => 'Language Class Registration - ' . $class_type . ' - Order #' . $order_id,
                'ack_template_name' => '',
                'deposit_date' => $this->formatDateForApi($date),
                'deposited_amount' => $formatted_amount,
                'parent_gift_id' => 0,
                'parent_external_id' => 0,
                'team_member' => ''
            ];
            
            // Create payment in LGL
            $connection = Connection::getInstance();
            $result = $connection->addPayment($lgl_id, $payment_data_to_send);
            
            if ($result['success']) {
                $this->debug('Class payment created', [
                    'lgl_id' => $lgl_id,
                    'order_id' => $order_id,
                    'class_type' => $class_type,
                    'amount' => $price
                ]);
                
                // Cache payment data
                if (isset($result['data']['id'])) {
                    CacheManager::cachePayment($result['data']['id'], $result['data']);
                }
            } else {
                $this->debug('Failed to create class payment', $result['error'] ?? 'Unknown error');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug('Exception in setupClassPayment', $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Setup event registration payment
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $order_id WooCommerce order ID
     * @param float $price Payment amount
     * @param string $date Payment date
     * @param string $event_name Event name
     * @return array Payment setup result
     */
    public function setupEventPayment(
        string $lgl_id, 
        int $order_id, 
        float $price, 
        string $date, 
        string $event_name
    ): array {
        try {
            // Get order details
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if (!$order) {
                    throw new \Exception('Order not found: ' . $order_id);
                }
            } else {
                throw new \Exception('WooCommerce not available');
            }
            
            // Get payment types for events
            $payment_data = $this->retrievePaymentTypes('Events');
            
            if (empty($payment_data)) {
                throw new \Exception('No payment types available for events');
            }
            
            // Determine payment method
            $payment_method = $order->get_payment_method();
            $gateway_title = $order->get_payment_method_title();
            
            // Find payment type
            // CRITICAL: Access the 'items' array within payment_types (LGL API response structure)
            $payment_types_items = $payment_data['payment_types']['items'] ?? [];
            $type_name = $this->determinePaymentType($payment_method);
            $type_id = $this->findLglObjectKey($type_name, 'name', $payment_types_items);
            
            // Fallback: If payment type not found, use first available type
            if (empty($type_id) && !empty($payment_types_items)) {
                $first_type = reset($payment_types_items);
                if ($first_type && isset($first_type['id'])) {
                    $type_id = $first_type['id'];
                    $type_name = $first_type['name'] ?? $type_name;
                    $this->debug('⚠️ Payment type not found, using first available', [
                        'requested' => $this->determinePaymentType($payment_method),
                        'using_id' => $type_id,
                        'using_name' => $type_name
                    ]);
                }
            }
            
            // Get fund ID from settings based on product category
            $fund_id = $this->getFundIdByProductCategory($order);
            
            // Get gift type and category for events (match legacy structure)
            $gift_types_items = $payment_data['gift_types']['items'] ?? [];
            $categories_items = $payment_data['categories']['items'] ?? [];
            $campaigns_items = $payment_data['campaigns']['items'] ?? [];
            
            $gift_type_name = 'Other Income';
            $gift_type_id = $this->findLglObjectKey($gift_type_name, 'name', $gift_types_items);
            
            // Find gift category - use "Event Fee" under "Other Income" gift type
            // Based on actual LGL structure: Other Income → Event Fee
            $category_name = 'Event Fee';
            $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            
            // Fallback to "Event fee" (lowercase) if "Event Fee" not found
            if (empty($category_id)) {
                $category_name = 'Event fee';
                $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            }
            
            // Final fallback to "Misc." if neither found (both under Other Income)
            if (empty($category_id)) {
                $category_name = 'Misc.';
                $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            }
            
            // Get campaign ID from settings (preferred) or fallback to name lookup
            $settingsManager = null;
            if (function_exists('lgl_get_container')) {
                try {
                    $container = lgl_get_container();
                    if ($container->has('admin.settings_manager')) {
                        $settingsManager = $container->get('admin.settings_manager');
                    }
                } catch (\Exception $e) {
                    // SettingsManager not available, will use fallback
                }
            }
            
            $campaign_id = null;
            if ($settingsManager) {
                $campaign_id = $settingsManager->get('campaign_id_events');
            }
            
            // Fallback to name lookup for backward compatibility
            if (empty($campaign_id)) {
                $campaign_name = 'Events';
                $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
                if (!$campaign_id) {
                    // Legacy fallback
                    $campaign_name = 'WACU Programming';
                    $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
                }
            } else {
                $campaign_name = 'Events'; // Use synced campaign name
            }
            
            // LGL API requires all amounts to be formatted with decimals
            $formatted_amount = number_format((float)$price, 2, '.', '');
            
            // Build payment data (MUST match membership structure for LGL gifts endpoint)
            $payment_data_to_send = [
                'external_id' => $order_id,
                'is_anon' => false,
                'gift_type_id' => $gift_type_id,
                'gift_type_name' => $gift_type_name,
                'gift_category_id' => $category_id,
                'gift_category_name' => $category_name,
                'campaign_id' => $campaign_id,
                'campaign_name' => $campaign_name,
                'fund_id' => $fund_id,
                'fund_name' => '',
                'appeal_id' => 0,
                'appeal_name' => '',
                'event_id' => 0,
                'event_name' => $event_name,
                'received_amount' => $formatted_amount,
                'received_date' => $this->formatDateForApi($date),
                'payment_type_id' => $type_id,
                'payment_type_name' => $type_name,
                'check_number' => '',
                'deductible_amount' => $formatted_amount,
                'note' => 'Event Registration - ' . $event_name . ' - Order #' . $order_id,
                'ack_template_name' => '',
                'deposit_date' => $this->formatDateForApi($date),
                'deposited_amount' => $formatted_amount,
                'parent_gift_id' => 0,
                'parent_external_id' => 0,
                'team_member' => ''
            ];
            
            // Create payment in LGL
            $connection = Connection::getInstance();
            $result = $connection->addPayment($lgl_id, $payment_data_to_send);
            
            if ($result['success']) {
                $this->debug('Event payment created', [
                    'lgl_id' => $lgl_id,
                    'order_id' => $order_id,
                    'event_name' => $event_name,
                    'amount' => $price
                ]);
                
                // Cache payment data
                if (isset($result['data']['id'])) {
                    CacheManager::cachePayment($result['data']['id'], $result['data']);
                }
            } else {
                $this->debug('Failed to create event payment', $result['error'] ?? 'Unknown error');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug('Exception in setupEventPayment', $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Add funds to LGL
     * 
     * @param array $fund_data Fund data
     * @return array Result of fund creation
     */
    public function addFunds(array $fund_data = []): array {
        try {
            $connection = Connection::getInstance();
            
            $default_funds = [
                [
                    'name' => 'General Fund',
                    'description' => 'General operating fund',
                    'is_active' => true
                ],
                [
                    'name' => 'Membership Fund',
                    'description' => 'Membership fees and renewals',
                    'is_active' => true
                ],
                [
                    'name' => 'Education Fund',
                    'description' => 'Classes and educational programs',
                    'is_active' => true
                ],
                [
                    'name' => 'Events Fund',
                    'description' => 'Special events and programs',
                    'is_active' => true
                ]
            ];
            
            $funds_to_create = !empty($fund_data) ? $fund_data : $default_funds;
            $results = [];
            
            foreach ($funds_to_create as $fund) {
                $result = $connection->makeRequest('funds', 'POST', $fund, false);
                $results[] = $result;
                
                if ($result['success']) {
                    $this->debug('Fund created', $fund['name']);
                } else {
                    $this->debug('Failed to create fund', ['fund_name' => $fund['name'], 'error' => $result['error'] ?? 'Unknown error']);
                }
            }
            
            return $results;
            
        } catch (\Exception $e) {
            $this->debug('Exception in addFunds', $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get fund ID by product category from settings
     * 
     * Determines the appropriate fund ID based on WooCommerce product category.
     * Settings always override legacy product meta fields.
     * 
     * @param \WC_Order $order WooCommerce order
     * @param mixed $specific_product Optional specific product to check (order item or product object)
     * @return int Fund ID from settings
     */
    private function getFundIdByProductCategory(\WC_Order $order, $specific_product = null): int {
        // Get SettingsManager instance from container
        $settingsManager = null;
        if (function_exists('lgl_get_container')) {
            try {
                $container = lgl_get_container();
                if ($container->has('admin.settings_manager')) {
                    $settingsManager = $container->get('admin.settings_manager');
                }
            } catch (\Exception $e) {
                // SettingsManager not available, will use defaults
            }
        }
        
        // Get Family Member Slots fund ID from settings (for comparison)
        $family_member_fund_id = $settingsManager ? (int) $settingsManager->get('fund_id_family_member_slots', 4147) : 4147;
        
        // CRITICAL FIX: If a specific product is provided, only check that product
        // This ensures correct fund ID for mixed carts
        if ($specific_product) {
            $product_obj = null;
            $product_id = null;
            $variation_id = null;
            
            // Handle order item object (WC_Order_Item_Product)
            if (is_object($specific_product) && method_exists($specific_product, 'get_product_id')) {
                $product_obj = $specific_product->get_product();
                $variation_id = $specific_product->get_variation_id();
                $product_id = $specific_product->get_product_id();
            }
            // Handle product object
            elseif (is_object($specific_product) && method_exists($specific_product, 'get_id')) {
                $product_obj = $specific_product;
                $variation_id = method_exists($specific_product, 'get_variation_id') ? $specific_product->get_variation_id() : null;
                $product_id = $specific_product->get_id();
            }
            
            if ($product_obj) {
                // Check for _ui_lgl_sync_id on variation or product
                $lgl_sync_id = null;
                if ($variation_id) {
                    $lgl_sync_id = get_post_meta($variation_id, '_ui_lgl_sync_id', true);
                }
                if (empty($lgl_sync_id) && $product_id) {
                    $lgl_sync_id = get_post_meta($product_id, '_ui_lgl_sync_id', true);
                }
                
                // CRITICAL FIX: _ui_lgl_sync_id contains membership level ID, not fund ID
                // Only use it as fund ID if it matches Family Member Slots fund (4147)
                // For regular memberships, use the membership fund from settings instead
                if (!empty($lgl_sync_id)) {
                    $sync_id_value = (int) $lgl_sync_id;
                    
                    // If this is a Family Member product (sync ID matches Family Member fund), use it as fund ID
                    if ($sync_id_value === $family_member_fund_id) {
                        $this->debug('Using _ui_lgl_sync_id as fund ID (Family Member product)', [
                            'product_id' => $product_id,
                            'variation_id' => $variation_id,
                            'fund_id' => $sync_id_value,
                            'product_name' => method_exists($product_obj, 'get_name') ? $product_obj->get_name() : 'N/A',
                            'is_family_member' => true
                        ]);
                        return $sync_id_value;
                    }
                    
                    // For regular membership products, _ui_lgl_sync_id is the membership level ID, not fund ID
                    // Fall through to category-based detection to get the correct membership fund
                    $this->debug('_ui_lgl_sync_id is membership level ID, not fund ID', [
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'membership_level_id' => $sync_id_value,
                        'product_name' => method_exists($product_obj, 'get_name') ? $product_obj->get_name() : 'N/A',
                        'note' => 'Will use membership fund from settings instead'
                    ]);
                }
                
                // Fallback to category-based detection (existing logic)
                $parent_id = method_exists($product_obj, 'get_parent_id') ? ($product_obj->get_parent_id() ?: $product_id) : $product_id;
                $categories = wp_get_post_terms($parent_id, 'product_cat', ['fields' => 'slugs']);
                
                $this->debug('Fund ID detection by category', [
                    'product_id' => $product_id,
                    'parent_id' => $parent_id,
                    'product_type' => method_exists($product_obj, 'get_type') ? $product_obj->get_type() : 'N/A',
                    'categories' => $categories
                ]);
                
                // Check for membership category (use 'memberships' plural as that's the actual slug)
                if (in_array('memberships', $categories) || in_array('membership', $categories)) {
                    $fund_id = $settingsManager ? (int) $settingsManager->get('fund_id_membership', 2437) : 2437;
                    $this->debug('Membership category detected', ['fund_id' => $fund_id]);
                    return $fund_id;
                }
                
                // Check for language class category
                if (in_array('language-class', $categories)) {
                    $fund_id = $settingsManager ? (int) $settingsManager->get('fund_id_language_classes', 4132) : 4132;
                    $this->debug('Language class category detected', ['fund_id' => $fund_id]);
                    return $fund_id;
                }
                
                // Check for events category
                if (in_array('events', $categories)) {
                    $fund_id = $settingsManager ? (int) $settingsManager->get('fund_id_events', 4142) : 4142;
                    $this->debug('Events category detected', ['fund_id' => $fund_id]);
                    return $fund_id;
                }
            }
        }
        
        // Fallback: Check all order items if no specific product provided
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $product_id = $product->get_id();
                $variation_id = $product->get_variation_id();
                
                // Check for _ui_lgl_sync_id on variation or product
                $lgl_sync_id = null;
                if ($variation_id) {
                    $lgl_sync_id = get_post_meta($variation_id, '_ui_lgl_sync_id', true);
                }
                if (empty($lgl_sync_id)) {
                    $lgl_sync_id = get_post_meta($product_id, '_ui_lgl_sync_id', true);
                }
                
                // CRITICAL FIX: _ui_lgl_sync_id contains membership level ID, not fund ID
                // Only use it as fund ID if it matches Family Member Slots fund (4147)
                // For regular memberships, use the membership fund from settings instead
                if (!empty($lgl_sync_id)) {
                    $sync_id_value = (int) $lgl_sync_id;
                    
                    // If this is a Family Member product (sync ID matches Family Member fund), use it as fund ID
                    if ($sync_id_value === $family_member_fund_id) {
                        $this->debug('Using _ui_lgl_sync_id as fund ID (Family Member product)', [
                            'product_id' => $product_id,
                            'variation_id' => $variation_id,
                            'fund_id' => $sync_id_value,
                            'product_name' => $product->get_name(),
                            'is_family_member' => true
                        ]);
                        return $sync_id_value;
                    }
                    
                    // For regular membership products, _ui_lgl_sync_id is the membership level ID, not fund ID
                    // Fall through to category-based detection to get the correct membership fund
                    $this->debug('_ui_lgl_sync_id is membership level ID, not fund ID', [
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'membership_level_id' => $sync_id_value,
                        'product_name' => $product->get_name(),
                        'note' => 'Will use membership fund from settings instead'
                    ]);
                }
                
                // Fallback to category-based detection (existing logic)
                $parent_id = $product->get_parent_id() ?: $product_id;
                $categories = wp_get_post_terms($parent_id, 'product_cat', ['fields' => 'slugs']);
                
                $this->debug('Fund ID detection by category', [
                    'product_id' => $product_id,
                    'parent_id' => $parent_id,
                    'product_type' => $product->get_type(),
                    'categories' => $categories
                ]);
                
                // Check for membership category (use 'memberships' plural as that's the actual slug)
                if (in_array('memberships', $categories) || in_array('membership', $categories)) {
                    $fund_id = $settingsManager ? (int) $settingsManager->get('fund_id_membership', 2437) : 2437;
                    $this->debug('Membership category detected', ['fund_id' => $fund_id]);
                    return $fund_id;
                }
                
                // Check for language class category
                if (in_array('language-class', $categories)) {
                    $fund_id = $settingsManager ? (int) $settingsManager->get('fund_id_language_classes', 4132) : 4132;
                    $this->debug('Language class category detected', ['fund_id' => $fund_id]);
                    return $fund_id;
                }
                
                // Check for events category
                if (in_array('events', $categories)) {
                    $fund_id = $settingsManager ? (int) $settingsManager->get('fund_id_events', 4142) : 4142;
                    $this->debug('Events category detected', ['fund_id' => $fund_id]);
                    return $fund_id;
                }
            }
        }
        
        // Default to general fund if no category matched
        $this->debug('No category matched, using general fund', []);
        return $settingsManager ? (int) $settingsManager->get('fund_id_general', 4127) : 4127;
    }
    
    /**
     * Generate descriptive payment note for membership payments
     * 
     * Creates category-specific notes:
     * - Family Member products: "Family Member Slot Purchase, Order #X"
     * - Regular memberships: "Membership Purchase - {level} - Order #X"
     * - Default: "Membership Purchase, Order #X"
     * 
     * @param \WC_Order $order WooCommerce order
     * @param int $order_id Order ID
     * @return string Payment note
     */
    private function generateMembershipPaymentNote(\WC_Order $order, int $order_id, $specific_product = null): string {
        // Get SettingsManager to check Family Member fund ID
        $settingsManager = null;
        $family_member_fund_id = null;
        
        if (function_exists('lgl_get_container')) {
            try {
                $container = lgl_get_container();
                if ($container->has('admin.settings_manager')) {
                    $settingsManager = $container->get('admin.settings_manager');
                    $family_member_fund_id = (int) $settingsManager->get('fund_id_family_member_slots', 4147);
                }
            } catch (\Exception $e) {
                $family_member_fund_id = 4147;
            }
        } else {
            $family_member_fund_id = 4147;
        }
        
        $is_family_member = false;
        $membership_level = null;
        $quantity = 1;
        
        // CRITICAL FIX: Check ONLY the specific product being processed, not all order items
        if ($specific_product) {
            $product_obj = null;
            
            // Handle different product object types
            if (is_object($specific_product) && method_exists($specific_product, 'get_product')) {
                // Order item object
                $product_obj = $specific_product->get_product();
                $quantity = $specific_product->get_quantity();
            } elseif (is_object($specific_product) && method_exists($specific_product, 'get_name')) {
                // Product object
                $product_obj = $specific_product;
            }
            
            if ($product_obj) {
                $product_name = method_exists($product_obj, 'get_name') ? $product_obj->get_name() : '';
                
                $variation_id = method_exists($product_obj, 'get_variation_id') ? $product_obj->get_variation_id() : null;
                $product_id = method_exists($product_obj, 'get_id') ? $product_obj->get_id() : null;
                
                // If specific_product is an order item, get IDs from it
                if (is_object($specific_product) && method_exists($specific_product, 'get_product_id')) {
                    $variation_id = $specific_product->get_variation_id();
                    $product_id = $specific_product->get_product_id();
                }
                
                // Check _ui_lgl_sync_id for Family Member detection
                $lgl_sync_id = null;
                if ($variation_id) {
                    $lgl_sync_id = get_post_meta($variation_id, '_ui_lgl_sync_id', true);
                }
                if (empty($lgl_sync_id) && $product_id) {
                    $lgl_sync_id = get_post_meta($product_id, '_ui_lgl_sync_id', true);
                }
                
                // Check if this is a Family Member product
                if (!empty($lgl_sync_id) && $family_member_fund_id > 0 && (int) $lgl_sync_id === $family_member_fund_id) {
                    $is_family_member = true;
                } elseif (stripos($product_name, 'Family Member') !== false) {
                    $is_family_member = true;
                }
                
                // Extract membership level from product name if not a family member product
                if (!$is_family_member && $product_name) {
                    $membership_names = ['Gateway Member', 'Crossroads Collective', 'World Horizon Patron', 'Member', 'Supporter', 'Patron', 'Community Member', 'Community Supporter', 'Community Patron'];
                    foreach ($membership_names as $level) {
                        if (stripos($product_name, $level) !== false) {
                            $membership_level = $level;
                            break;
                        }
                    }
                }
            }
        }
        
        // Generate base note based on product type
        $base_note = '';
        if ($is_family_member) {
            if ($quantity > 1) {
                $base_note = sprintf('Family Member Slot Purchase (%d slots), Order #%d', $quantity, $order_id);
            } else {
                $base_note = sprintf('Family Member Slot Purchase, Order #%d', $order_id);
            }
        } elseif ($membership_level) {
            $base_note = sprintf('Membership Purchase - %s - Order #%d', $membership_level, $order_id);
        } else {
            // Default fallback
            $base_note = sprintf('Membership Purchase, Order #%d', $order_id);
        }
        
        // Append coupon information if coupons were used
        $coupon_info = $this->getCouponInfoForNote($order);
        if (!empty($coupon_info)) {
            $base_note .= ' | ' . $coupon_info;
            $this->debug('📝 Payments: Added coupon info to payment note', [
                'order_id' => $order_id,
                'base_note' => $base_note,
                'coupon_info' => $coupon_info,
                'final_note' => $base_note
            ]);
        } else {
            $this->debug('ℹ️ Payments: No coupon info to add to payment note', [
                'order_id' => $order_id,
                'base_note' => $base_note
            ]);
        }
        
        return $base_note;
    }
    
    /**
     * Get coupon information formatted for LGL gift note
     * 
     * @param \WC_Order $order WooCommerce order
     * @return string Formatted coupon information or empty string
     */
    private function getCouponInfoForNote(\WC_Order $order): string {
        $coupon_codes = $order->get_coupon_codes();
        
        if (empty($coupon_codes)) {
            return '';
        }
        
        // Get coupon role assignments
        $coupon_info_parts = [];
        
        try {
            // Try to get CouponRoleMeta service
            $couponRoleMeta = null;
            $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
            
            if ($container->has('woocommerce.coupon_role_meta')) {
                $couponRoleMeta = $container->get('woocommerce.coupon_role_meta');
            }
            
            // Format role labels
            $role_labels = [
                'ui_teacher' => 'Teacher',
                'ui_board' => 'Board Member',
                'ui_vip' => 'VIP',
                'ui_member' => 'Member'
            ];
            
            foreach ($coupon_codes as $coupon_code) {
                $coupon_str = strtoupper($coupon_code);
                
                // Try to get role assignment if CouponRoleMeta is available
                if ($couponRoleMeta) {
                    $role_assignment = $couponRoleMeta->getCouponRoleAssignment($coupon_code);
                    
                    if ($role_assignment) {
                        $role = $role_assignment['wp_role'] ?? null;
                        $scholarship_type = $role_assignment['scholarship_type'] ?? null;
                        
                        if ($role) {
                            $role_label = $role_labels[$role] ?? ucfirst(str_replace('ui_', '', $role));
                            $coupon_str .= ' (' . $role_label;
                            
                            // Add scholarship type if applicable
                            if (!empty($scholarship_type) && $scholarship_type !== 'none') {
                                $scholarship_label = $scholarship_type === 'partial' 
                                    ? 'Partial Scholarship' 
                                    : 'Full Scholarship';
                                $coupon_str .= ' - ' . $scholarship_label;
                            }
                            
                            $coupon_str .= ')';
                        }
                    }
                }
                
                $coupon_info_parts[] = $coupon_str;
            }
        } catch (\Exception $e) {
            // Fallback: just list coupon codes if there's an error
            $this->debug('Error getting coupon info for note', [
                'error' => $e->getMessage(),
                'coupon_codes' => $coupon_codes
            ]);
            $coupon_info_parts = array_map('strtoupper', $coupon_codes);
        }
        
        if (empty($coupon_info_parts)) {
            return '';
        }
        
        return 'Coupon: ' . implode(', ', $coupon_info_parts);
    }
    
    /**
     * Determine membership fund based on order
     * 
     * @deprecated Use getFundIdByProductCategory() instead
     * @param \WC_Order $order WooCommerce order
     * @return string Fund name
     */
    private function determineMembershipFund(\WC_Order $order): string {
        // Check order items for membership type
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
                
                if (in_array('Membership', $categories) || in_array('memberships', $categories)) {
                    return 'Membership'; // Match exact LGL fund name
                }
            }
        }
        
        return 'General Fund';
    }
    
    /**
     * Determine class fund based on class type
     * 
     * @param string $class_type Class type
     * @return string Fund name
     */
    private function determineClassFund(string $class_type): string {
        $class_fund_mappings = [
            'language' => 'Education Fund',
            'conversation' => 'Education Fund',
            'workshop' => 'Education Fund',
            'seminar' => 'Education Fund'
        ];
        
        $class_type_lower = strtolower($class_type);
        
        foreach ($class_fund_mappings as $keyword => $fund) {
            if (strpos($class_type_lower, $keyword) !== false) {
                return $fund;
            }
        }
        
        return 'Education Fund';
    }
    
    /**
     * Determine event fund based on event name
     * 
     * @param string $event_name Event name
     * @return string Fund name
     */
    private function determineEventFund(string $event_name): string {
        $event_fund_mappings = [
            'gala' => 'Events Fund',
            'dinner' => 'Events Fund',
            'fundraiser' => 'General Fund',
            'cultural' => 'Events Fund',
            'festival' => 'Events Fund'
        ];
        
        $event_name_lower = strtolower($event_name);
        
        foreach ($event_fund_mappings as $keyword => $fund) {
            if (strpos($event_name_lower, $keyword) !== false) {
                return $fund;
            }
        }
        
        return 'Events Fund';
    }
    
    /**
     * Determine payment type from WooCommerce payment method
     * 
     * @param string $payment_method WooCommerce payment method
     * @return string LGL payment type
     */
    private function determinePaymentType(string $payment_method): string {
        $payment_type_mappings = [
            'stripe' => 'Credit Card',
            'paypal' => 'PayPal',
            'bacs' => 'Bank Transfer',
            'cheque' => 'Check',
            'cod' => 'Cash',
            'square' => 'Credit Card'
        ];
        
        return $payment_type_mappings[$payment_method] ?? 'Credit Card';
    }
    
    /**
     * Format date for API
     * 
     * @param string|int $date Date string or timestamp
     * @return string Formatted date
     */
    private function formatDateForApi($date): string {
        if (is_numeric($date)) {
            return date('Y-m-d', $date);
        }
        
        $timestamp = strtotime($date);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : date('Y-m-d');
    }
    
    /**
     * Debug output with conditional display
     * 
     * @param string $message Debug message
     * @param mixed $data Optional data to display
     */
    private function debug(string $message, $data = null): void {
        $helper = Helper::getInstance();
        $helper->debug('LGL Payments: ' . $message, $data);
    }
    
    /**
     * Get payment statistics
     * 
     * @return array Payment statistics
     */
    public function getPaymentStats(): array {
        return [
            'cache_entries' => count($this->paymentTypesCache),
            'last_retrieval' => $this->paymentTypesCache['last_update'] ?? null,
            'api_settings_valid' => !empty($this->lgl->getApiKey())
        ];
    }
}

// Maintain backward compatibility
if (!function_exists('lgl_payments')) {
    function lgl_payments(): Payments {
        return Payments::getInstance();
    }
}
