<?php
/**
 * LGL Relations Manager
 * 
 * Manages relationships between WordPress entities and Little Green Light data.
 * Handles JetEngine relationships, family connections, and data associations.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

use UpstateInternational\LGL\Core\CacheManager;

/**
 * Relations Manager Class
 * 
 * Manages relationships between WordPress and LGL entities
 */
class RelationsManager {
    
    /**
     * Class instance
     * 
     * @var RelationsManager|null
     */
    private static $instance = null;
    
    /**
     * User to family relationships
     * 
     * @var array
     */
    private $userToFamily = [];
    
    /**
     * Order to classes relationships
     * 
     * @var array
     */
    private $orderToClasses = [];
    
    /**
     * Orders to memberships relationships
     * 
     * @var array
     */
    private $ordersToMemberships = [];
    
    /**
     * User to class registrations relationships
     * 
     * @var array
     */
    private $userToClassRegistrations = [];
    
    /**
     * User to orders relationships
     * 
     * @var array
     */
    private $userToOrders = [];
    
    /**
     * All relations cache
     * 
     * @var array
     */
    private $allRelations = [];
    
    /**
     * Debug mode flag
     */
    const DEBUG_MODE = true;
    
    /**
     * Get instance
     * 
     * @return RelationsManager
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
        $this->initializeRelationsManager();
    }
    
    /**
     * Initialize relations manager
     */
    private function initializeRelationsManager(): void {
        // Initialize JetEngine integration if available
        if (function_exists('jet_engine')) {
            add_action('jet_engine_relations_init', [$this, 'initializeJetEngineRelations']);
        }
        
        // Initialize relationship tracking
        $this->initializeRelationshipTracking();
        
        // error_log('LGL Relations Manager: Initialized successfully');
    }
    
    /**
     * Initialize JetEngine relations
     */
    public function initializeJetEngineRelations(): void {
        try {
            // Register custom relations if JetEngine is available
            if (class_exists('Jet_Engine_Relations')) {
                $this->registerCustomRelations();
            }
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error initializing JetEngine relations: ' . $e->getMessage());
        }
    }
    
    /**
     * Register custom relations
     */
    private function registerCustomRelations(): void {
        $relations = [
            'user_to_family' => [
                'name' => 'user_to_family',
                'parent_object' => 'users',
                'child_object' => 'users',
                'parent_control' => true,
                'child_control' => false,
                'parent_title' => 'Primary Member',
                'child_title' => 'Family Members'
            ],
            'order_to_classes' => [
                'name' => 'order_to_classes',
                'parent_object' => 'shop_order',
                'child_object' => 'ui-classes',
                'parent_control' => true,
                'child_control' => false,
                'parent_title' => 'Order',
                'child_title' => 'Classes'
            ],
            'user_to_registrations' => [
                'name' => 'user_to_registrations',
                'parent_object' => 'users',
                'child_object' => 'class-registration',
                'parent_control' => true,
                'child_control' => false,
                'parent_title' => 'User',
                'child_title' => 'Registrations'
            ]
        ];
        
        foreach ($relations as $relation_id => $relation_config) {
            $this->registerRelation($relation_id, $relation_config);
        }
    }
    
    /**
     * Register a single relation
     * 
     * @param string $relation_id Relation identifier
     * @param array $config Relation configuration
     */
    private function registerRelation(string $relation_id, array $config): void {
        try {
            // This would integrate with JetEngine's relation registration
            // For now, we'll store the configuration for future use
            $this->allRelations[$relation_id] = $config;
            
            $this->debug('Relation registered', $relation_id);
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error registering relation ' . $relation_id . ': ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize relationship tracking
     */
    private function initializeRelationshipTracking(): void {
        // Hook into WordPress actions to track relationships
        add_action('user_register', [$this, 'trackUserRegistration']);
        add_action('woocommerce_new_order', [$this, 'trackNewOrder']);
        add_action('save_post', [$this, 'trackPostSave'], 10, 2);
    }
    
    /**
     * Track user registration
     * 
     * @param int $user_id WordPress user ID
     */
    public function trackUserRegistration(int $user_id): void {
        try {
            // Check if this is a family member registration
            $parent_user_id = get_user_meta($user_id, 'parent_user_id', true);
            
            if ($parent_user_id) {
                $this->createUserToFamilyRelation($parent_user_id, $user_id);
            }
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error tracking user registration: ' . $e->getMessage());
        }
    }
    
    /**
     * Track new order
     * 
     * @param int $order_id WooCommerce order ID
     */
    public function trackNewOrder(int $order_id): void {
        try {
            if (!function_exists('wc_get_order')) {
                return;
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
            
            $user_id = $order->get_customer_id();
            
            if ($user_id) {
                $this->createUserToOrderRelation($user_id, $order_id);
                
                // Check for class or membership items
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if ($product) {
                        $this->processOrderItem($order_id, $user_id, $product, $item);
                    }
                }
            }
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error tracking new order: ' . $e->getMessage());
        }
    }
    
    /**
     * Track post save
     * 
     * @param int $post_id Post ID
     * @param \WP_Post $post Post object
     */
    public function trackPostSave(int $post_id, \WP_Post $post): void {
        try {
            // Track class registration posts
            if ($post->post_type === 'class-registration') {
                $user_id = get_post_meta($post_id, 'user_id', true);
                if ($user_id) {
                    $this->createUserToClassRegistrationRelation($user_id, $post_id);
                }
            }
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error tracking post save: ' . $e->getMessage());
        }
    }
    
    /**
     * Create user to family relation
     * 
     * @param int $parent_user_id Parent user ID
     * @param int $child_user_id Child user ID
     * @return bool Success/failure
     */
    public function createUserToFamilyRelation(int $parent_user_id, int $child_user_id): bool {
        try {
            $relation_key = $parent_user_id . '_' . $child_user_id;
            
            $this->userToFamily[$relation_key] = [
                'parent_id' => $parent_user_id,
                'child_id' => $child_user_id,
                'relation_type' => 'family',
                'created_at' => current_time('mysql')
            ];
            
            // Store in database
            add_user_meta($child_user_id, 'family_parent_id', $parent_user_id);
            
            $this->debug('User to family relation created', $relation_key);
            
            return true;
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error creating user to family relation: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create user to order relation
     * 
     * @param int $user_id User ID
     * @param int $order_id Order ID
     * @return bool Success/failure
     */
    public function createUserToOrderRelation(int $user_id, int $order_id): bool {
        try {
            $relation_key = $user_id . '_' . $order_id;
            
            $this->userToOrders[$relation_key] = [
                'user_id' => $user_id,
                'order_id' => $order_id,
                'created_at' => current_time('mysql')
            ];
            
            $this->debug('User to order relation created', $relation_key);
            
            return true;
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error creating user to order relation: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create user to class registration relation
     * 
     * @param int $user_id User ID
     * @param int $registration_id Registration post ID
     * @return bool Success/failure
     */
    public function createUserToClassRegistrationRelation(int $user_id, int $registration_id): bool {
        try {
            $relation_key = $user_id . '_' . $registration_id;
            
            $this->userToClassRegistrations[$relation_key] = [
                'user_id' => $user_id,
                'registration_id' => $registration_id,
                'created_at' => current_time('mysql')
            ];
            
            $this->debug('User to class registration relation created', $relation_key);
            
            return true;
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error creating user to class registration relation: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process order item for relations
     * 
     * @param int $order_id Order ID
     * @param int $user_id User ID
     * @param \WC_Product $product Product object
     * @param \WC_Order_Item $item Order item
     */
    private function processOrderItem(int $order_id, int $user_id, \WC_Product $product, \WC_Order_Item $item): void {
        try {
            $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
            
            // Check if it's a class
            if (in_array('Classes', $product_categories) || in_array('classes', $product_categories)) {
                $class_id = get_post_meta($product->get_id(), 'related_class_id', true);
                if ($class_id) {
                    $this->createOrderToClassRelation($order_id, $class_id);
                }
            }
            
            // Check if it's a membership
            if (in_array('Membership', $product_categories) || in_array('memberships', $product_categories)) {
                $this->createOrderToMembershipRelation($order_id, $product->get_id());
            }
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error processing order item: ' . $e->getMessage());
        }
    }
    
    /**
     * Create order to class relation
     * 
     * @param int $order_id Order ID
     * @param int $class_id Class ID
     * @return bool Success/failure
     */
    private function createOrderToClassRelation(int $order_id, int $class_id): bool {
        try {
            $relation_key = $order_id . '_' . $class_id;
            
            $this->orderToClasses[$relation_key] = [
                'order_id' => $order_id,
                'class_id' => $class_id,
                'created_at' => current_time('mysql')
            ];
            
            $this->debug('Order to class relation created', $relation_key);
            
            return true;
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error creating order to class relation: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create order to membership relation
     * 
     * @param int $order_id Order ID
     * @param int $membership_id Membership product ID
     * @return bool Success/failure
     */
    private function createOrderToMembershipRelation(int $order_id, int $membership_id): bool {
        try {
            $relation_key = $order_id . '_' . $membership_id;
            
            $this->ordersToMemberships[$relation_key] = [
                'order_id' => $order_id,
                'membership_id' => $membership_id,
                'created_at' => current_time('mysql')
            ];
            
            $this->debug('Order to membership relation created', $relation_key);
            
            return true;
            
        } catch (\Exception $e) {
            error_log('LGL Relations Manager: Error creating order to membership relation: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get family members for user
     * 
     * @param int $user_id User ID
     * @return array Family member IDs
     */
    public function getFamilyMembers(int $user_id): array {
        $family_members = [];
        
        foreach ($this->userToFamily as $relation) {
            if ($relation['parent_id'] === $user_id) {
                $family_members[] = $relation['child_id'];
            }
        }
        
        // Also check database
        $family_meta = get_users([
            'meta_key' => 'family_parent_id',
            'meta_value' => $user_id,
            'fields' => 'ID'
        ]);
        
        return array_unique(array_merge($family_members, $family_meta));
    }
    
    /**
     * Get relations statistics
     * 
     * @return array Relations statistics
     */
    public function getRelationsStats(): array {
        return [
            'user_to_family' => count($this->userToFamily),
            'order_to_classes' => count($this->orderToClasses),
            'orders_to_memberships' => count($this->ordersToMemberships),
            'user_to_class_registrations' => count($this->userToClassRegistrations),
            'user_to_orders' => count($this->userToOrders),
            'total_relations' => count($this->allRelations)
        ];
    }
    
    /**
     * Debug output with conditional display
     * 
     * @param string $message Debug message
     * @param mixed $data Optional data to display
     */
    private function debug(string $message, $data = null): void {
        if (!static::DEBUG_MODE) {
            return;
        }
        
        $log_message = 'LGL Relations Manager: ' . $message;
        if ($data !== null) {
            $log_message .= ' ' . print_r($data, true);
        }
        
        error_log($log_message);
    }
}

// Maintain backward compatibility
if (!function_exists('lgl_relations_manager')) {
    function lgl_relations_manager(): RelationsManager {
        return RelationsManager::getInstance();
    }
}
