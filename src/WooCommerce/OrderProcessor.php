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
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param WpUsers $wpUsers LGL WP Users service
     * @param MembershipOrderHandler $membershipHandler Membership order handler
     * @param ClassOrderHandler $classHandler Class order handler
     * @param EventOrderHandler $eventHandler Event order handler
     */
    public function __construct(
        Helper $helper,
        WpUsers $wpUsers,
        MembershipOrderHandler $membershipHandler,
        ClassOrderHandler $classHandler,
        EventOrderHandler $eventHandler
    ) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
        $this->membershipHandler = $membershipHandler;
        $this->classHandler = $classHandler;
        $this->eventHandler = $eventHandler;
    }
    
    /**
     * Process completed order
     * 
     * Main entry point for processing WooCommerce orders after successful checkout
     * 
     * @param int $order_id WooCommerce order ID
     * @return void
     */
    public function processCompletedOrder(int $order_id): void {
        if (!class_exists('WC_Order')) {
            $this->helper->debug('WooCommerce not available');
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->helper->debug('OrderProcessor: Order not found', $order_id);
            return;
        }
        
        $this->helper->debug('OrderProcessor: Processing completed order', $order_id);
        
        // Get customer and payment information
        $uid = $order->get_customer_id();
        $this->updatePaymentMethod($uid, $order);
        
        // Extract order metadata
        $order_meta = $this->extractOrderMetadata($order);
        
        // Process order products
        $this->processOrderProducts($order, $uid, $order_meta);
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
     * Process order products
     * 
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
        
        $i = 0;
        $attendee_index = 0;
        
        foreach ($products as $product) {
            $product_name = $product->get_name();
            $quantity = $product->get_quantity();
            $product_id = $product->get_variation_id() ?: $product->get_product_id();
            $parent_id = $product->get_variation_id() ? $product->get_product_id() : $product_id;
            
            $this->helper->debug('OrderProcessor: Processing product', [
                'name' => $product_name,
                'quantity' => $quantity,
                'product_id' => $product_id,
                'parent_id' => $parent_id
            ]);
            
            // Process different product types
            if ($this->isMembershipProduct($parent_id, $product_name)) {
                $this->processMembershipProduct($uid, $order, $order_meta, $product, $product_name);
                
            } elseif ($this->isLanguageClassProduct($parent_id)) {
                $this->processLanguageClassProduct($uid, $order, $order_meta, $product);
                
            } elseif ($this->isEventProduct($parent_id)) {
                $create_attendee_cct_flag = true;
                
                // Collect attendee information
                $attendees = array_merge(
                    $attendees,
                    $this->collectAttendeeData($order, $product, $quantity, $attendee_index)
                );
                
                // Process event registration (only once per parent product)
                if ($i === 0 && !in_array($parent_id, $processed_events)) {
                    $this->processEventProduct($uid, $order, $order_meta, $product);
                    $processed_events[] = $parent_id;
                    $i++;
                } else {
                    $i++;
                }
            }
        }
        
        // Create attendee CCT records if needed
        if ($create_attendee_cct_flag && !empty($attendees)) {
            $this->createAttendeeCCTRecords($order, $attendees);
        }
    }
    
    /**
     * Check if product is a membership product
     * 
     * @param int $parent_id Parent product ID
     * @param string $product_name Product name
     * @return bool
     */
    private function isMembershipProduct(int $parent_id, string $product_name): bool {
        return has_term('memberships', 'product_cat', $parent_id) && 
               stripos($product_name, 'membership') !== false;
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
     * Process membership product
     * 
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
        $this->helper->debug('OrderProcessor: Running doWooCommerceLGLMembership');
        $this->membershipHandler->processOrder($uid, $order, $order_meta, $product, $product_name);
    }
    
    /**
     * Process language class product
     * 
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
        $this->helper->debug('OrderProcessor: Running doWooCommerceLGLClassRegistration');
        $this->classHandler->processOrder($uid, $order, $order_meta, $product);
    }
    
    /**
     * Process event product
     * 
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
        $this->helper->debug('OrderProcessor: Running doWooCommerceLGLEventRegistration');
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
            
            $attendee = [
                'attendee_name' => $attendee_name,
                'attendee_email' => $attendee_email,
                'product' => $product,
                'product_id' => $product_id,
                'parent_id' => $parent_id,
                'variation_name' => $this->helper->getVariationName($product_id),
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
