<?php
/**
 * Class Order Handler
 * 
 * Handles WooCommerce language class orders and processes them in LGL CRM.
 * Manages class registration and payment processing.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;
use UpstateInternational\LGL\Core\ServiceContainer;
use UpstateInternational\LGL\JetFormBuilder\Actions\ClassRegistrationAction;

/**
 * ClassOrderHandler Class
 * 
 * Processes language class orders from WooCommerce
 */
class ClassOrderHandler {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * LGL WP Users service
     * 
     * @var WpUsers
     */
    private WpUsers $wpUsers;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param WpUsers $wpUsers LGL WP Users service
     */
    public function __construct(Helper $helper, WpUsers $wpUsers) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
    }
    
    /**
     * Process class order
     * 
     * Handles language class orders from WooCommerce and registers them in LGL
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    /**
     * Process class order (immediate tasks only)
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    public function processOrderImmediate(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $product_name = $product->get_name();
        $product_id = $product->get_product_id();
        
        $this->helper->debug('⚡ ClassOrderHandler::processOrderImmediate() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'product_name' => $product_name,
            'product_id' => $product_id,
            'mode' => 'immediate_only'
        ]);
        
        // Build class registration data (fund ID determined internally by payment method)
        $class_registration = $this->buildClassRegistration($uid, $order, $order_meta, $product);
        
        // Update user data (skip ALL LGL sync - LGL sync happens separately in async processing)
        $this->wpUsers->updateUserData($class_registration, $order, $order_meta, true, true);
        
        // Create JetEngine CCT for class registration
        $cct_result = $this->wpUsers->createClassRegistrationCct($order, $product_id, $order_meta);
        
        if ($cct_result['success']) {
            $this->helper->debug('ClassOrderHandler: Class CCT created', [
                'item_id' => $cct_result['item_id'],
                'class_name' => $cct_result['class_name']
            ]);
        } else {
            $this->helper->debug('ClassOrderHandler: Failed to create class CCT', [
                'error' => $cct_result['error']
            ]);
        }
        
        // Complete the order
        $order->update_status('completed');
        
        $this->helper->debug('✅ ClassOrderHandler::processOrderImmediate() COMPLETED', $order->get_id());
    }
    
    /**
     * Process class order (LGL sync only)
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    public function processOrderLglSync(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $product_name = $product->get_name();
        $product_id = $product->get_product_id();
        
        $this->helper->debug('🔄 ClassOrderHandler::processOrderLglSync() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'product_name' => $product_name,
            'product_id' => $product_id,
            'mode' => 'lgl_sync_only'
        ]);
        
        // Build class registration data (fund ID determined internally by payment method)
        $class_registration = $this->buildClassRegistration($uid, $order, $order_meta, $product);
        
        // Register class in LGL (API calls only)
        $this->registerClassInLGL($class_registration);
        
        $this->helper->debug('✅ ClassOrderHandler::processOrderLglSync() COMPLETED', $order->get_id());
    }
    
    /**
     * Process class order (legacy - full sync)
     * 
     * @deprecated Use processOrderImmediate() + processOrderLglSync() instead
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    public function processOrder(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $product_name = $product->get_name();
        $product_id = $product->get_product_id();
        
        $this->helper->debug('ClassOrderHandler: Processing class order (LEGACY)', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'product_name' => $product_name,
            'product_id' => $product_id
        ]);
        
        // Build class registration data (fund ID determined internally by payment method)
        $class_registration = $this->buildClassRegistration($uid, $order, $order_meta, $product);
        
        // Update user data (skip ALL LGL sync - LGL sync happens separately in async processing)
        $this->wpUsers->updateUserData($class_registration, $order, $order_meta, true, true);
        
        // Create JetEngine CCT for class registration
        $cct_result = $this->wpUsers->createClassRegistrationCct($order, $product_id, $order_meta);
        
        if ($cct_result['success']) {
            $this->helper->debug('ClassOrderHandler: Class CCT created', [
                'item_id' => $cct_result['item_id'],
                'class_name' => $cct_result['class_name']
            ]);
        } else {
            $this->helper->debug('ClassOrderHandler: Failed to create class CCT', [
                'error' => $cct_result['error']
            ]);
        }
        
        // Register class in LGL
        $this->registerClassInLGL($class_registration);
        
        // Complete the order
        $order->update_status('completed');
        
        $this->helper->debug('ClassOrderHandler: Class order completed', $order->get_id());
    }
    
    /**
     * Build class registration data
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return array Class registration data
     */
    private function buildClassRegistration(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): array {
        $class_name = $product->get_name(); // e.g., "Spanish C1 Regular"
        
        return [
            'user_id' => $uid,
            'class_id' => $product->get_product_id(),
            'username' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'user_firstname' => $order->get_billing_first_name(),
            'user_lastname' => $order->get_billing_last_name(),
            'user_email' => $order->get_billing_email(),
            'user_phone' => $order->get_billing_phone(),
            'class_name' => $class_name,
            'class_price' => $order->get_total(),
            'event_name' => $class_name, // Populate for LGL event auto-creation
            'user_preferred_language' => $order_meta['languages'] ?? '',
            'user_home_country' => $order_meta['country'] ?? '',
            'order_notes' => get_post_meta($order->get_id(), '_order_notes', true),
            'inserted_post_id' => $order->get_id(),
        ];
    }
    
    /**
     * Register class in LGL
     * 
     * Uses the modern ClassRegistrationAction instead of legacy LGL_API.
     * This ensures consistent behavior between form-based and WooCommerce-based class registrations.
     * 
     * @param array $class_registration Class registration data
     * @return void
     */
    private function registerClassInLGL(array $class_registration): void {
        try {
            // Get the modern ClassRegistrationAction from the service container
            $container = ServiceContainer::getInstance();
            
            // Get action instance - container will auto-resolve dependencies
            $action = new ClassRegistrationAction(
                $container->get(\UpstateInternational\LGL\LGL\Connection::class),
                $container->get(\UpstateInternational\LGL\LGL\Helper::class),
                $container->get(\UpstateInternational\LGL\LGL\Payments::class)
            );
            
            // Prepare request array matching JetFormBuilder format
            // Ensure username is properly formatted (spaces replaced with %20)
            $request = $class_registration;
            if (isset($request['username'])) {
                $request['username'] = str_replace(' ', '%20', $request['username']);
            }
            
            // Call the action handler programmatically (null action_handler for non-form calls)
            $action->handle($request, null);
            
            $this->helper->debug('ClassOrderHandler: Class registered using ClassRegistrationAction', $class_registration['user_id']);
            
        } catch (\Exception $e) {
            $this->helper->debug('ClassOrderHandler: Error registering class', [
                'user_id' => $class_registration['user_id'] ?? null,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Fallback to legacy method if modern action fails
            if (class_exists('LGL_API')) {
                $this->helper->debug('ClassOrderHandler: Falling back to legacy LGL_API method');
                $lgl_api = \LGL_API::get_instance();
                $lgl_api->lgl_add_class_registration($class_registration, null);
            }
        }
    }
    
    /**
     * Validate class order
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param mixed $product Product item
     * @return array Validation result
     */
    public function validateOrder(int $uid, \WC_Order $order, $product): array {
        $result = [
            'valid' => false,
            'errors' => [],
            'user_id' => $uid,
            'order_id' => $order->get_id()
        ];
        
        // Validate user ID
        if ($uid <= 0) {
            $result['errors'][] = 'Invalid user ID';
        }
        
        // Validate user exists
        $user_data = get_userdata($uid);
        if (!$user_data) {
            $result['errors'][] = 'User not found';
        }
        
        // Validate order
        if (!$order->get_id()) {
            $result['errors'][] = 'Invalid order';
        }
        
        // Validate product
        if (!$product || !$product->get_product_id()) {
            $result['errors'][] = 'Invalid product';
        }
        
        // Validate required billing information
        $required_fields = [
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_email' => $order->get_billing_email()
        ];
        
        foreach ($required_fields as $field => $value) {
            if (empty($value)) {
                $result['errors'][] = "Missing required field: {$field}";
            }
        }
        
        // Validate product is a language class
        $product_id = $product->get_product_id();
        if (!has_term('language-class', 'product_cat', $product_id)) {
            $result['errors'][] = 'Product is not a language class';
        }
        
        $result['valid'] = empty($result['errors']);
        
        return $result;
    }
    
    /**
     * Get class product meta fields
     * 
     * @param int $product_id Product ID
     * @return array Product meta data
     */
    public function getClassProductMeta(int $product_id): array {
        $product_meta = get_post_meta($product_id);
        
        // Check for unified LGL Sync ID first, fallback to legacy field
        $lgl_fund_id = get_post_meta($product_id, '_ui_lgl_sync_id', true);
        if (empty($lgl_fund_id)) {
            $lgl_fund_id = $product_meta['_lc_lgl_fund_id'][0] ?? '';
        }
        
        return [
            'lgl_fund_id' => $lgl_fund_id,
            'class_level' => $product_meta['_class_level'][0] ?? '',
            'class_duration' => $product_meta['_class_duration'][0] ?? '',
            'class_schedule' => $product_meta['_class_schedule'][0] ?? '',
        ];
    }
    
    /**
     * Check if product is a language class
     * 
     * @param int $product_id Product ID
     * @return bool
     */
    public function isLanguageClass(int $product_id): bool {
        return has_term('language-class', 'product_cat', $product_id);
    }
    
    /**
     * Get handler status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'woocommerce_available' => class_exists('WC_Order'),
            'lgl_api_available' => class_exists('LGL_API'),
            'jetengine_available' => function_exists('jet_engine'),
            'dependencies_met' => class_exists('WC_Order') && class_exists('LGL_API')
        ];
    }
    
    /**
     * Get required product categories
     * 
     * @return array<string>
     */
    public function getRequiredCategories(): array {
        return ['language-class'];
    }
    
    /**
     * Get required meta fields
     * 
     * @return array<string>
     */
    public function getRequiredMetaFields(): array {
        return [
            '_ui_lgl_sync_id', // Unified LGL fund ID (preferred)
            '_lc_lgl_fund_id' // Legacy field (fallback)
        ];
    }
}
