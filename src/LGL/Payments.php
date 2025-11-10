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
        int $order_id, 
        float $price, 
        string $date, 
        ?string $payment_type = null
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
            
            // Find appropriate fund
            $fund_name = $this->determineMembershipFund($order);
            $fund_id = $this->findLglObjectKey($fund_name, 'name', $funds_items);
            
            // Find campaign (match legacy: 'Membership Fees')
            $campaign_name = 'Membership Fees';
            $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
            
            // Find gift category (match legacy: 'Donation')
            $category_name = 'Donation';
            $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            
            // Find gift type (match legacy: 'Other Income')
            $gift_type_name = 'Other Income';
            $gift_type_id = $this->findLglObjectKey($gift_type_name, 'name', $gift_types_items);
            
            // Find payment type
            $type_name = $payment_type ?: $this->determinePaymentType($payment_method);
            $type_id = $this->findLglObjectKey($type_name, 'name', $payment_types_items);
            
            // Build payment data (match legacy format)
            // LGL API requires all amounts to be formatted with decimals
            $formatted_amount = number_format((float)$price, 2, '.', '');
            
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
                'note' => 'Website Registration, Order #' . $order_id,
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
     * @param string $lgl_class_id LGL class ID
     * @return array Payment setup result
     */
    public function setupClassPayment(
        string $lgl_id, 
        int $order_id, 
        float $price, 
        string $date, 
        string $class_type, 
        string $lgl_class_id
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
            $payment_data = $this->retrievePaymentTypes('Classes', null, null, $lgl_class_id);
            
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
            
            // CRITICAL: Use the passed fund ID directly (from _ui_lgl_sync_id or legacy _lc_lgl_fund_id)
            // The $lgl_class_id parameter is actually the fund ID from your JetEngine meta
            $fund_id = (int) $lgl_class_id;
            
            // Get gift type and category for classes (match legacy structure)
            $gift_types_items = $payment_data['gift_types']['items'] ?? [];
            $categories_items = $payment_data['categories']['items'] ?? [];
            $campaigns_items = $payment_data['campaigns']['items'] ?? [];
            
            $gift_type_name = 'Other Income';
            $gift_type_id = $this->findLglObjectKey($gift_type_name, 'name', $gift_types_items);
            
            $category_name = 'Language Classes';  // Match your LGL setup
            $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            
            $campaign_name = 'Language Class';  // Match your LGL setup
            $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
            
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
                'event_name' => '',
                'received_amount' => $formatted_amount,
                'received_date' => $this->formatDateForApi($date),
                'payment_type_id' => $type_id,
                'payment_type_name' => $type_name,
                'check_number' => '',
                'deductible_amount' => $formatted_amount,
                'note' => 'Class registration - ' . $class_type . ' - Order #' . $order_id,
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
     * @param string $lgl_class_id LGL event/class ID
     * @return array Payment setup result
     */
    public function setupEventPayment(
        string $lgl_id, 
        int $order_id, 
        float $price, 
        string $date, 
        string $event_name, 
        string $lgl_class_id
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
            $payment_data = $this->retrievePaymentTypes('Events', null, null, $lgl_class_id);
            
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
            
            // CRITICAL: Use the passed fund ID directly (from _ui_lgl_sync_id or legacy _ui_event_lgl_fund_id)
            // The $lgl_class_id parameter is actually the fund ID from your JetEngine meta
            $fund_id = (int) $lgl_class_id;
            
            // Get gift type and category for events (match legacy structure)
            $gift_types_items = $payment_data['gift_types']['items'] ?? [];
            $categories_items = $payment_data['categories']['items'] ?? [];
            $campaigns_items = $payment_data['campaigns']['items'] ?? [];
            
            $gift_type_name = 'Other Income';
            $gift_type_id = $this->findLglObjectKey($gift_type_name, 'name', $gift_types_items);
            
            $category_name = 'Event fee';  // Match your LGL setup
            $category_id = $this->findLglObjectKey($category_name, 'display_name', $categories_items);
            
            $campaign_name = 'WACU Programming';  // Match your LGL setup
            $campaign_id = $this->findLglObjectKey($campaign_name, 'name', $campaigns_items);
            
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
                'note' => 'Event registration - ' . $event_name . ' - Order #' . $order_id,
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
     * Determine membership fund based on order
     * 
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
