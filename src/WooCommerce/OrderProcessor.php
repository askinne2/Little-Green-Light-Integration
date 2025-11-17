<?php
/**
 * Order Processor
 * 
 * Handles WooCommerce order processing after successful checkout.
 * Processes different product types (memberships, classes, events) and manages attendees.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;

/**
 * OrderProcessor Class
 * 
 * Main order processing logic for WooCommerce integration
 */
class OrderProcessor {
    
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
     * Membership Order Handler
     * 
     * @var MembershipOrderHandler
     */
    private MembershipOrderHandler $membershipHandler;
    
    /**
     * Class Order Handler
     * 
     * @var ClassOrderHandler
     */
    private ClassOrderHandler $classHandler;
    
    /**
     * Event Order Handler
     * 
     * @var EventOrderHandler
     */
    private EventOrderHandler $eventHandler;
    
    /**
     * Async Order Processor
     * 
     * @var AsyncOrderProcessor|null
     */
    private ?AsyncOrderProcessor $asyncProcessor = null;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param WpUsers $wpUsers LGL WP Users service
     * @param MembershipOrderHandler $membershipHandler Membership order handler
     * @param ClassOrderHandler $classHandler Class order handler
     * @param EventOrderHandler $eventHandler Event order handler
     * @param AsyncOrderProcessor|null $asyncProcessor Async order processor (optional)
     */
    public function __construct(
        Helper $helper,
        WpUsers $wpUsers,
        MembershipOrderHandler $membershipHandler,
        ClassOrderHandler $classHandler,
        EventOrderHandler $eventHandler,
        ?AsyncOrderProcessor $asyncProcessor = null
    ) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
        $this->membershipHandler = $membershipHandler;
        $this->classHandler = $classHandler;
        $this->eventHandler = $eventHandler;
        $this->asyncProcessor = $asyncProcessor;
    }
    
    /**
     * Set async processor
     * 
     * @param AsyncOrderProcessor $asyncProcessor Async order processor
     * @return void
     */
    public function setAsyncProcessor(AsyncOrderProcessor $asyncProcessor): void {
        $this->asyncProcessor = $asyncProcessor;
    }
    
    /**
     * Process completed order
     * 
     * Main entry point for processing WooCommerce orders after successful checkout.
     * Handles immediate tasks (WP admin) and schedules async LGL API sync.
     * 
     * @param int $order_id WooCommerce order ID
     * @return void
     */
    public function processCompletedOrder(int $order_id): void {
        $this->helper->debug('🚀 OrderProcessor::processCompletedOrder() STARTED', [
            'order_id' => $order_id,
            'timestamp' => current_time('mysql'),
            'memory_usage' => memory_get_usage(true) / 1024 / 1024 . 'MB',
            'hook' => current_action()
        ]);
        
        if (!class_exists('WC_Order')) {
            $this->helper->debug('❌ OrderProcessor: WooCommerce not available - WC_Order class missing');
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->helper->debug('❌ OrderProcessor: Order not found', $order_id);
            return;
        }
        
        // Prevent duplicate processing
        $processing_key = '_lgl_processing_' . $order_id;
        if (get_transient($processing_key)) {
            $this->helper->debug('⏭️ OrderProcessor: Order already being processed, skipping duplicate', [
                'order_id' => $order_id,
                'hook' => current_action()
            ]);
            return;
        }
        
        // Check if already processed
        $processed_at = $order->get_meta('_lgl_processed_at');
        if ($processed_at) {
            $this->helper->debug('⏭️ OrderProcessor: Order already processed, skipping', [
                'order_id' => $order_id,
                'processed_at' => $processed_at,
                'hook' => current_action()
            ]);
            return;
        }
        
        // Set processing lock (expires in 5 minutes)
        set_transient($processing_key, true, 5 * MINUTE_IN_SECONDS);
        
        try {
            // Log detailed order information
            $this->helper->debug('📋 OrderProcessor: Order Details', [
                'order_id' => $order_id,
                'order_status' => $order->get_status(),
                'order_total' => $order->get_total(),
                'customer_id' => $order->get_customer_id(),
                'customer_email' => $order->get_billing_email(),
                'payment_method' => $order->get_payment_method(),
                'order_date' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : 'Unknown',
                'item_count' => count($order->get_items())
            ]);
            
            // Get customer and payment information
            $uid = $order->get_customer_id();
            $this->helper->debug('👤 OrderProcessor: Customer Info', [
                'user_id' => $uid,
                'billing_email' => $order->get_billing_email(),
                'billing_first_name' => $order->get_billing_first_name(),
                'billing_last_name' => $order->get_billing_last_name(),
                'billing_phone' => $order->get_billing_phone()
            ]);
            
            // STEP 1: Process immediate tasks (synchronous - fast)
            $this->helper->debug('⚡ OrderProcessor: Processing immediate tasks...');
            $order_meta = $this->extractOrderMetadata($order);
            $this->processImmediateTasks($order, $uid, $order_meta);
            
            // STEP 2: Schedule async LGL API sync (non-blocking)
            if ($this->asyncProcessor) {
                $this->helper->debug('⏰ OrderProcessor: Scheduling async LGL sync...');
                $this->asyncProcessor->scheduleAsyncProcessing($order_id);
            } else {
                // Fallback: process synchronously if async processor not available
                $this->helper->debug('⚠️ OrderProcessor: Async processor not available, processing synchronously');
                $this->processLglSyncOnly($order_id);
            }
            
            // Mark as processed
            $order->update_meta_data('_lgl_processed', true);
            $order->update_meta_data('_lgl_processed_at', current_time('mysql'));
            $order->update_meta_data('_lgl_processed_by', current_action());
            $order->save();
            
            $this->helper->debug('✅ OrderProcessor::processCompletedOrder() COMPLETED', [
                'order_id' => $order_id,
                'final_memory_usage' => memory_get_usage(true) / 1024 / 1024 . 'MB',
                'async_scheduled' => $this->asyncProcessor !== null
            ]);
            
        } finally {
            // Remove processing lock
            delete_transient($processing_key);
        }
    }
    
    /**
     * Process immediate tasks (synchronous)
     * 
     * Handles fast WordPress operations that don't require LGL API calls:
     * - User data updates
     * - CCT creation
     * - Order status updates
     * 
     * @param \WC_Order $order WooCommerce order
     * @param int $uid User ID
     * @param array $order_meta Order metadata
     * @return void
     */
    private function processImmediateTasks(\WC_Order $order, int $uid, array $order_meta): void {
        $this->helper->debug('⚡ OrderProcessor::processImmediateTasks() STARTED', [
            'order_id' => $order->get_id(),
            'user_id' => $uid
        ]);
        
        // Update payment method user meta
        $this->updatePaymentMethod($uid, $order);
        
        // Process order products (immediate tasks only - skip LGL API calls)
        $this->processOrderProductsImmediate($order, $uid, $order_meta);
        
        $this->helper->debug('✅ OrderProcessor::processImmediateTasks() COMPLETED', [
            'order_id' => $order->get_id()
        ]);
    }
    
    /**
     * Process LGL sync only (async)
     * 
     * Handles LGL API calls that can be done in background:
     * - Constituent creation/updates
     * - Payment creation
     * - Membership registration
     * 
     * @param int $order_id WooCommerce order ID
     * @return void
     */
    public function processLglSyncOnly(int $order_id): void {
        $this->helper->debug('🔄 OrderProcessor::processLglSyncOnly() STARTED', [
            'order_id' => $order_id,
            'timestamp' => current_time('mysql')
        ]);
        
        if (!class_exists('WC_Order')) {
            $this->helper->debug('❌ OrderProcessor: WooCommerce not available');
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->helper->debug('❌ OrderProcessor: Order not found', $order_id);
            return;
        }
        
        $uid = $order->get_customer_id();
        $order_meta = $this->extractOrderMetadata($order);
        
        // Process order products (LGL API calls only)
        $this->processOrderProductsLglSync($order, $uid, $order_meta);
        
        $this->helper->debug('✅ OrderProcessor::processLglSyncOnly() COMPLETED', [
            'order_id' => $order_id
        ]);
    }
    
    /**
     * Update payment method user meta
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @return void
     */
    private function updatePaymentMethod(int $uid, \WC_Order $order): void {
        $payment_type = $order->get_payment_method();
        
        if ($payment_type !== 'cheque') {
            update_user_meta($uid, 'payment-method', 'online');
        } else {
            update_user_meta($uid, 'payment-method', 'offline');
        }
        
        $this->helper->debug('OrderProcessor: Payment method updated', [
            'user_id' => $uid,
            'payment_method' => $payment_type,
            'stored_as' => get_user_meta($uid, 'payment-method', true)
        ]);
    }
    
    /**
     * Extract order metadata
     * 
     * @param \WC_Order $order WooCommerce order
     * @return array Order metadata
     */
    private function extractOrderMetadata(\WC_Order $order): array {
        return [
            'languages' => $order->get_meta('_order_languages_spoken'),
            'country' => $order->get_meta('_order_country_of_origin'),
            'referral' => $order->get_meta('_order_referral_source'),
            'reason' => $order->get_meta('_order_reason_for_membership'),
            'about' => $order->get_meta('_order_tell_us_about_yourself')
        ];
    }
    
    /**
     * Process order products (immediate tasks only)
     * 
     * Handles WordPress operations without LGL API calls
     * 
     * @param \WC_Order $order WooCommerce order
     * @param int $uid User ID
     * @param array $order_meta Order metadata
     * @return void
     */
    private function processOrderProductsImmediate(\WC_Order $order, int $uid, array $order_meta): void {
        $products = $order->get_items();
        $processed_events = [];
        $attendees = [];
        $create_attendee_cct_flag = false;
        
        $this->helper->debug('⚡ OrderProcessor::processOrderProductsImmediate() STARTED', [
            'total_products' => count($products),
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'mode' => 'immediate_only'
        ]);
        
        $i = 0;
        $attendee_index = 0;
        
        foreach ($products as $product_item) {
            $product_name = $product_item->get_name();
            $quantity = $product_item->get_quantity();
            $product_id = $product_item->get_variation_id() ?: $product_item->get_product_id();
            $parent_id = $product_item->get_variation_id() ? $product_item->get_product_id() : $product_id;
            
            // Get product categories for debugging
            $product_categories = wp_get_post_terms($parent_id, 'product_cat', ['fields' => 'slugs']);
            
            $this->helper->debug('🔍 OrderProcessor: Processing Product #' . ($i + 1) . ' (IMMEDIATE)', [
                'name' => $product_name,
                'quantity' => $quantity,
                'product_id' => $product_id,
                'parent_id' => $parent_id,
                'categories' => $product_categories
            ]);
            
            // Check product type detection
            $is_membership = $this->isMembershipProduct($parent_id, $product_name);
            $is_class = $this->isLanguageClassProduct($parent_id);
            $is_event = $this->isEventProduct($parent_id);
            
            // Process different product types (immediate tasks only - skip LGL API calls)
            if ($is_membership) {
                $this->helper->debug('🎯 OrderProcessor: Processing membership (IMMEDIATE)', [
                    'product_name' => $product_name
                ]);
                $this->processMembershipProductImmediate($uid, $order, $order_meta, $product_item, $product_name);
                
            } elseif ($is_class) {
                $this->helper->debug('📚 OrderProcessor: Processing class (IMMEDIATE)', [
                    'product_name' => $product_name
                ]);
                $this->processLanguageClassProductImmediate($uid, $order, $order_meta, $product_item);
                
            } elseif ($is_event) {
                $this->helper->debug('🎪 OrderProcessor: Processing event (IMMEDIATE)', [
                    'product_name' => $product_name
                ]);
                $create_attendee_cct_flag = true;
                
                // Collect attendee information
                $attendees = array_merge(
                    $attendees,
                    $this->collectAttendeeData($order, $product_item, $quantity, $attendee_index)
                );
                
                // Process event registration (immediate tasks only)
                if ($i === 0 && !in_array($parent_id, $processed_events)) {
                    $this->processEventProductImmediate($uid, $order, $order_meta, $product_item);
                    $processed_events[] = $parent_id;
                    $i++;
                } else {
                    $i++;
                }
            } else {
                $this->helper->debug('⚠️ OrderProcessor: UNRECOGNIZED PRODUCT TYPE', [
                    'product_name' => $product_name,
                    'product_id' => $product_id,
                    'categories' => $product_categories
                ]);
            }
        }
        
        // Create attendee CCT records if needed
        if ($create_attendee_cct_flag && !empty($attendees)) {
            $this->helper->debug('👥 OrderProcessor: Creating attendee CCT records', count($attendees));
            $this->createAttendeeCCTRecords($order, $attendees);
        }
        
        $this->helper->debug('✅ OrderProcessor::processOrderProductsImmediate() COMPLETED', [
            'products_processed' => count($products),
            'events_processed' => count($processed_events),
            'attendees_created' => count($attendees)
        ]);
    }
    
    /**
     * Process order products (LGL sync only)
     * 
     * Handles LGL API calls for background processing
     * 
     * @param \WC_Order $order WooCommerce order
     * @param int $uid User ID
     * @param array $order_meta Order metadata
     * @return void
     */
    private function processOrderProductsLglSync(\WC_Order $order, int $uid, array $order_meta): void {
        $products = $order->get_items();
        $processed_events = [];
        
        $this->helper->debug('🔄 OrderProcessor::processOrderProductsLglSync() STARTED', [
            'total_products' => count($products),
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'mode' => 'lgl_sync_only'
        ]);
        
        $i = 0;
        
        foreach ($products as $product_item) {
            $product_name = $product_item->get_name();
            $product_id = $product_item->get_variation_id() ?: $product_item->get_product_id();
            $parent_id = $product_item->get_variation_id() ? $product_item->get_product_id() : $product_id;
            
            // Check product type detection
            $is_membership = $this->isMembershipProduct($parent_id, $product_name);
            $is_class = $this->isLanguageClassProduct($parent_id);
            $is_event = $this->isEventProduct($parent_id);
            
            // Process different product types (LGL API calls only)
            if ($is_membership) {
                $this->helper->debug('🎯 OrderProcessor: Processing membership (LGL SYNC)', [
                    'product_name' => $product_name
                ]);
                $this->processMembershipProductLglSync($uid, $order, $order_meta, $product_item, $product_name);
                
            } elseif ($is_class) {
                $this->helper->debug('📚 OrderProcessor: Processing class (LGL SYNC)', [
                    'product_name' => $product_name
                ]);
                $this->processLanguageClassProductLglSync($uid, $order, $order_meta, $product_item);
                
            } elseif ($is_event) {
                $this->helper->debug('🎪 OrderProcessor: Processing event (LGL SYNC)', [
                    'product_name' => $product_name
                ]);
                
                // Process event registration (LGL sync only)
                if ($i === 0 && !in_array($parent_id, $processed_events)) {
                    $this->processEventProductLglSync($uid, $order, $order_meta, $product_item);
                    $processed_events[] = $parent_id;
                    $i++;
                } else {
                    $i++;
                }
            }
        }
        
        $this->helper->debug('✅ OrderProcessor::processOrderProductsLglSync() COMPLETED', [
            'products_processed' => count($products),
            'events_processed' => count($processed_events)
        ]);
    }
    
    /**
     * Process order products (legacy - full sync)
     * 
     * @deprecated Use processOrderProductsImmediate() + processOrderProductsLglSync() instead
     * @param \WC_Order $order WooCommerce order
     * @param int $uid User ID
     * @param array $order_meta Order metadata
     * @return void
     */
    private function processOrderProducts(\WC_Order $order, int $uid, array $order_meta): void {
        $products = $order->get_items();
        $processed_events = [];
        $attendees = [];
        $create_attendee_cct_flag = false;
        
        $this->helper->debug('🛍️ OrderProcessor::processOrderProducts() STARTED', [
            'total_products' => count($products),
            'user_id' => $uid,
            'order_id' => $order->get_id()
        ]);
        
        $i = 0;
        $attendee_index = 0;
        
        foreach ($products as $product_item) {
            $product_name = $product_item->get_name();
            $quantity = $product_item->get_quantity();
            $product_id = $product_item->get_variation_id() ?: $product_item->get_product_id();
            $parent_id = $product_item->get_variation_id() ? $product_item->get_product_id() : $product_id;
            
            // Get product categories for debugging
            $product_categories = wp_get_post_terms($parent_id, 'product_cat', ['fields' => 'slugs']);
            
            $this->helper->debug('🔍 OrderProcessor: Processing Product #' . ($i + 1), [
                'name' => $product_name,
                'quantity' => $quantity,
                'product_id' => $product_id,
                'parent_id' => $parent_id,
                'categories' => $product_categories,
                'item_total' => $product_item->get_total(),
                'item_subtotal' => $product_item->get_subtotal()
            ]);
            
            // Check product type detection
            $is_membership = $this->isMembershipProduct($parent_id, $product_name);
            $is_class = $this->isLanguageClassProduct($parent_id);
            $is_event = $this->isEventProduct($parent_id);
            
            $this->helper->debug('🏷️ OrderProcessor: Product Type Detection', [
                'product_name' => $product_name,
                'is_membership' => $is_membership ? 'YES' : 'NO',
                'is_language_class' => $is_class ? 'YES' : 'NO', 
                'is_event' => $is_event ? 'YES' : 'NO',
                'categories' => $product_categories
            ]);
            
            // Process different product types
            if ($is_membership) {
                $this->helper->debug('🎯 OrderProcessor: ROUTING TO MEMBERSHIP HANDLER', [
                    'product_name' => $product_name,
                    'handler' => 'MembershipOrderHandler'
                ]);
                $this->processMembershipProduct($uid, $order, $order_meta, $product_item, $product_name);
                
            } elseif ($is_class) {
                $this->helper->debug('📚 OrderProcessor: ROUTING TO CLASS HANDLER (Legacy)', [
                    'product_name' => $product_name,
                    'handler' => 'ClassOrderHandler'
                ]);
                $this->processLanguageClassProduct($uid, $order, $order_meta, $product_item);
                
            } elseif ($is_event) {
                $this->helper->debug('🎪 OrderProcessor: ROUTING TO EVENT HANDLER', [
                    'product_name' => $product_name,
                    'handler' => 'EventOrderHandler'
                ]);
                $create_attendee_cct_flag = true;
                
                // Collect attendee information
                $attendees = array_merge(
                    $attendees,
                    $this->collectAttendeeData($order, $product_item, $quantity, $attendee_index)
                );
                
                // Process event registration (only once per parent product)
                if ($i === 0 && !in_array($parent_id, $processed_events)) {
                    $this->processEventProduct($uid, $order, $order_meta, $product_item);
                    $processed_events[] = $parent_id;
                    $i++;
                } else {
                    $i++;
                }
            } else {
                $this->helper->debug('⚠️ OrderProcessor: UNRECOGNIZED PRODUCT TYPE', [
                    'product_name' => $product_name,
                    'product_id' => $product_id,
                    'categories' => $product_categories,
                    'action' => 'Skipping product - no handler available'
                ]);
            }
        }
        
        // Create attendee CCT records if needed
        if ($create_attendee_cct_flag && !empty($attendees)) {
            $this->helper->debug('👥 OrderProcessor: Creating attendee CCT records', count($attendees));
            $this->createAttendeeCCTRecords($order, $attendees);
        }
        
        $this->helper->debug('✅ OrderProcessor::processOrderProducts() COMPLETED', [
            'products_processed' => count($products),
            'events_processed' => count($processed_events),
            'attendees_created' => count($attendees)
        ]);
    }
    
    /**
     * Check if product is a membership product
     * 
     * Recognizes both old subscription-based products (containing "membership")
     * and new one-time products (Member, Supporter, Patron, etc.)
     * 
     * @param int $parent_id Parent product ID
     * @param string $product_name Product name
     * @return bool
     */
    private function isMembershipProduct(int $parent_id, string $product_name): bool {
        // Must be in memberships category
        if (!has_term('memberships', 'product_cat', $parent_id)) {
            return false;
        }
        
        // New one-time membership products (2024+ model)
        $new_membership_names = [
            'Member',
            'Supporter', 
            'Patron',
            'Family Member' // Token-based family slot product
        ];
        
        foreach ($new_membership_names as $name) {
            if (stripos($product_name, $name) !== false) {
                return true;
            }
        }
        
        // Legacy subscription products (contains "membership")
        if (stripos($product_name, 'membership') !== false) {
            return true;
        }
        
        // If in memberships category but name doesn't match known patterns,
        // still treat as membership (category is authoritative)
        $this->helper->debug('⚠️ OrderProcessor: Unknown membership product name in memberships category', [
            'product_name' => $product_name,
            'parent_id' => $parent_id,
            'treating_as' => 'membership'
        ]);
        
        return true;
    }
    
    /**
     * Check if product is a language class product
     * 
     * @param int $parent_id Parent product ID
     * @return bool
     */
    private function isLanguageClassProduct(int $parent_id): bool {
        return has_term('language-class', 'product_cat', $parent_id);
    }
    
    /**
     * Check if product is an event product
     * 
     * @param int $parent_id Parent product ID
     * @return bool
     */
    private function isEventProduct(int $parent_id): bool {
        return has_term('events', 'product_cat', $parent_id);
    }
    
    /**
     * Process membership product (immediate tasks only)
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @param string $product_name Product name
     * @return void
     */
    private function processMembershipProductImmediate(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product,
        string $product_name
    ): void {
        $this->helper->debug('🎯 OrderProcessor::processMembershipProductImmediate() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'product_name' => $product_name,
            'mode' => 'immediate_only'
        ]);
        
        try {
            $this->membershipHandler->processOrderImmediate($uid, $order, $order_meta, $product, $product_name);
            $this->helper->debug('✅ OrderProcessor: MembershipOrderHandler (immediate) completed');
        } catch (\Exception $e) {
            $this->helper->debug('❌ OrderProcessor: MembershipOrderHandler (immediate) FAILED', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Process membership product (LGL sync only)
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @param string $product_name Product name
     * @return void
     */
    private function processMembershipProductLglSync(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product,
        string $product_name
    ): void {
        $this->helper->debug('🎯 OrderProcessor::processMembershipProductLglSync() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'product_name' => $product_name,
            'mode' => 'lgl_sync_only'
        ]);
        
        try {
            $this->membershipHandler->processOrderLglSync($uid, $order, $order_meta, $product, $product_name);
            $this->helper->debug('✅ OrderProcessor: MembershipOrderHandler (LGL sync) completed');
        } catch (\Exception $e) {
            $this->helper->debug('❌ OrderProcessor: MembershipOrderHandler (LGL sync) FAILED', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Process membership product (legacy - full sync)
     * 
     * @deprecated Use processMembershipProductImmediate() + processMembershipProductLglSync() instead
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @param string $product_name Product name
     * @return void
     */
    private function processMembershipProduct(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product,
        string $product_name
    ): void {
        $this->helper->debug('🎯 OrderProcessor::processMembershipProduct() STARTED (LEGACY)', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'product_name' => $product_name
        ]);
        
        try {
            $this->membershipHandler->processOrder($uid, $order, $order_meta, $product, $product_name);
            $this->helper->debug('✅ OrderProcessor: MembershipOrderHandler completed successfully');
        } catch (\Exception $e) {
            $this->helper->debug('❌ OrderProcessor: MembershipOrderHandler FAILED', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Process language class product (immediate tasks only)
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    private function processLanguageClassProductImmediate(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $this->helper->debug('📚 OrderProcessor::processLanguageClassProductImmediate() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'mode' => 'immediate_only'
        ]);
        $this->classHandler->processOrderImmediate($uid, $order, $order_meta, $product);
    }
    
    /**
     * Process language class product (LGL sync only)
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    private function processLanguageClassProductLglSync(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $this->helper->debug('📚 OrderProcessor::processLanguageClassProductLglSync() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'mode' => 'lgl_sync_only'
        ]);
        $this->classHandler->processOrderLglSync($uid, $order, $order_meta, $product);
    }
    
    /**
     * Process language class product (legacy - full sync)
     * 
     * @deprecated Use processLanguageClassProductImmediate() + processLanguageClassProductLglSync() instead
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    private function processLanguageClassProduct(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $this->helper->debug('OrderProcessor: Running doWooCommerceLGLClassRegistration (LEGACY)');
        $this->classHandler->processOrder($uid, $order, $order_meta, $product);
    }
    
    /**
     * Process event product (immediate tasks only)
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    private function processEventProductImmediate(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $this->helper->debug('🎪 OrderProcessor::processEventProductImmediate() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'mode' => 'immediate_only'
        ]);
        $this->eventHandler->processOrderImmediate($uid, $order, $order_meta, $product);
    }
    
    /**
     * Process event product (LGL sync only)
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    private function processEventProductLglSync(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $this->helper->debug('🎪 OrderProcessor::processEventProductLglSync() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'mode' => 'lgl_sync_only'
        ]);
        $this->eventHandler->processOrderLglSync($uid, $order, $order_meta, $product);
    }
    
    /**
     * Process event product (legacy - full sync)
     * 
     * @deprecated Use processEventProductImmediate() + processEventProductLglSync() instead
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @return void
     */
    private function processEventProduct(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product
    ): void {
        $this->helper->debug('OrderProcessor: Running doWooCommerceLGLEventRegistration (LEGACY)');
        $this->eventHandler->processOrder($uid, $order, $order_meta, $product);
    }
    
    /**
     * Collect attendee data for events
     * 
     * @param \WC_Order $order WooCommerce order
     * @param mixed $product Product item
     * @param int $quantity Product quantity
     * @param int &$attendee_index Attendee index counter (by reference)
     * @return array Attendee data
     */
    private function collectAttendeeData(
        \WC_Order $order,
        $product,
        int $quantity,
        int &$attendee_index
    ): array {
        $attendees = [];
        $product_id = $product->get_variation_id() ?: $product->get_product_id();
        $parent_id = $product->get_variation_id() ? $product->get_product_id() : $product_id;
        
        for ($j = 0; $j < $quantity; $j++) {
            $suffix = $attendee_index === 0 ? '' : '_' . $attendee_index;
            $attendee_name = $order->get_meta('attendee_name' . $suffix);
            $attendee_email = $order->get_meta('attendee_email' . $suffix);
            
            if (empty($attendee_name) || empty($attendee_email)) {
                continue; // Skip if the meta fields are empty
            }
            
            // Get product name (variation or parent product name)
            $product_obj = wc_get_product($product_id);
            $variation_name = $product_obj ? $product_obj->get_name() : '';
            
            $attendee = [
                'attendee_name' => $attendee_name,
                'attendee_email' => $attendee_email,
                'product' => $product,
                'product_id' => $product_id,
                'parent_id' => $parent_id,
                'variation_name' => $variation_name,
            ];
            
            $this->helper->debug('OrderProcessor: Adding new attendee', $attendee['attendee_email']);
            $attendees[] = $attendee;
            $attendee_index++;
        }
        
        return $attendees;
    }
    
    /**
     * Create attendee CCT records
     * 
     * @param \WC_Order $order WooCommerce order
     * @param array $attendees Attendee data
     * @return void
     */
    private function createAttendeeCCTRecords(\WC_Order $order, array $attendees): void {
        $this->wpUsers->createEventRegistrationCct($order, $attendees);
        $this->helper->debug('OrderProcessor: Created attendee CCT records', count($attendees));
    }
    
    /**
     * Get required WooCommerce dependencies
     * 
     * @return array<string>
     */
    public function getRequiredDependencies(): array {
        return [
            'WooCommerce'
        ];
    }
    
    /**
     * Check if WooCommerce is available
     * 
     * @return bool
     */
    public function isWooCommerceAvailable(): bool {
        return class_exists('WC_Order') && class_exists('WooCommerce');
    }
    
    /**
     * Get processor status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'woocommerce_available' => $this->isWooCommerceAvailable(),
            'handlers_loaded' => [
                'membership' => isset($this->membershipHandler),
                'class' => isset($this->classHandler),
                'event' => isset($this->eventHandler)
            ],
            'dependencies_met' => $this->isWooCommerceAvailable()
        ];
    }
}
