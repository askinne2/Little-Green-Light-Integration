<?php
/**
 * Event Order Handler
 * 
 * Handles WooCommerce event orders and processes them in LGL CRM.
 * Manages event registration and payment processing.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;

/**
 * EventOrderHandler Class
 * 
 * Processes event orders from WooCommerce
 */
class EventOrderHandler {
    
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
     * Process event order
     * 
     * Handles event orders from WooCommerce and registers them in LGL
     * 
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
        $quantity = $product->get_quantity();
        $product_id = $product->get_variation_id() ?: $product->get_product_id();
        $parent_id = $product->get_variation_id() ? $product->get_product_id() : $product_id;
        
        $this->helper->debug('EventOrderHandler: Processing event order', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'product_name' => $product_name,
            'product_id' => $product_id,
            'parent_id' => $parent_id,
            'quantity' => $quantity
        ]);
        
        // Get LGL fund ID from product meta
        $lgl_fund_id = $this->getLglFundId($parent_id);
        
        // Build event registration data
        $event_registration = $this->buildEventRegistration($uid, $order, $order_meta, $product, $lgl_fund_id);
        
        // Update user data
        $this->wpUsers->updateUserData($event_registration, $order, $order_meta);
        
        // Register event in LGL
        $this->registerEventInLGL($event_registration);
        
        // Complete the order
        $order->update_status('completed');
        
        $this->helper->debug('EventOrderHandler: Event order completed', $order->get_id());
    }
    
    /**
     * Get LGL fund ID from product meta
     * 
     * @param int $parent_id Parent product ID
     * @return string LGL fund ID
     */
    private function getLglFundId(int $parent_id): string {
        // Check for unified LGL Sync ID field first (new standard)
        $lgl_fund_id = get_post_meta($parent_id, '_ui_lgl_sync_id', true);
        $source = 'unified';
        
        if (empty($lgl_fund_id)) {
            // Fallback to legacy event-specific field
            $product_meta = get_post_meta($parent_id);
            $lgl_fund_id = $product_meta['_ui_event_lgl_fund_id'][0] ?? '';
            $source = 'legacy';
        }
        
        $this->helper->debug('EventOrderHandler: Event Registration LGL FUND ID', [
            'parent_id' => $parent_id,
            'fund_id' => $lgl_fund_id,
            'source' => $source
        ]);
        
        return $lgl_fund_id;
    }
    
    /**
     * Build event registration data
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @param string $lgl_fund_id LGL fund ID
     * @return array Event registration data
     */
    private function buildEventRegistration(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product,
        string $lgl_fund_id
    ): array {
        $product_name = $product->get_name();
        $product_id = $product->get_variation_id() ?: $product->get_product_id();
        
        return [
            'user_id' => $uid,
            'class_id' => $product_id, // Using 'class_id' for backward compatibility
            'username' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'user_firstname' => $order->get_billing_first_name(),
            'user_lastname' => $order->get_billing_last_name(),
            'user_email' => $order->get_billing_email(),
            'user_phone' => $order->get_billing_phone(),
            'event_name' => $product_name,
            'event_price' => $order->get_total(),
            'lgl_fund_id' => $lgl_fund_id,
            'user_preferred_language' => $order_meta['languages'] ?? '',
            'user_home_country' => $order_meta['country'] ?? '',
            'order_notes' => get_post_meta($order->get_id(), '_order_notes', true),
            'inserted_post_id' => $order->get_id(),
        ];
    }
    
    /**
     * Register event in LGL
     * 
     * @param array $event_registration Event registration data
     * @return void
     */
    private function registerEventInLGL(array $event_registration): void {
        // Use legacy LGL_API for now - this will be modernized in future phases
        if (class_exists('LGL_API')) {
            $lgl_api = \LGL_API::get_instance();
            $lgl_api->lgl_add_event_registration($event_registration, null);
            $this->helper->debug('EventOrderHandler: Event registered in LGL', $event_registration['user_id']);
        } else {
            $this->helper->debug('EventOrderHandler: LGL_API not available for event registration');
        }
    }
    
    /**
     * Validate event order
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
        
        // Validate product is an event
        $product_id = $product->get_product_id();
        $parent_id = $product->get_variation_id() ? $product->get_product_id() : $product_id;
        
        if (!has_term('events', 'product_cat', $parent_id)) {
            $result['errors'][] = 'Product is not an event';
        }
        
        $result['valid'] = empty($result['errors']);
        
        return $result;
    }
    
    /**
     * Get event product meta fields
     * 
     * @param int $product_id Product ID
     * @return array Product meta data
     */
    public function getEventProductMeta(int $product_id): array {
        $product_meta = get_post_meta($product_id);
        
        // Check for unified LGL Sync ID first, fallback to legacy field
        $lgl_fund_id = get_post_meta($product_id, '_ui_lgl_sync_id', true);
        if (empty($lgl_fund_id)) {
            $lgl_fund_id = $product_meta['_ui_event_lgl_fund_id'][0] ?? '';
        }
        
        return [
            'lgl_fund_id' => $lgl_fund_id,
            'start_datetime' => $product_meta['_ui_event_start_datetime'][0] ?? '',
            'end_datetime' => $product_meta['_ui_event_end_datetime'][0] ?? '',
            'location_name' => $product_meta['_ui_event_location_name'][0] ?? '',
            'location_address' => $product_meta['_ui_event_location_address'][0] ?? '',
            'speaker_name' => $product_meta['_ui_event_speaker_name'][0] ?? '',
            'discussion_topic' => $product_meta['_ui_event_discussion_topic'][0] ?? '',
            'max_attendees' => $product_meta['_ui_event_max_attendees'][0] ?? '',
        ];
    }
    
    /**
     * Check if product is an event
     * 
     * @param int $product_id Product ID
     * @return bool
     */
    public function isEvent(int $product_id): bool {
        return has_term('events', 'product_cat', $product_id);
    }
    
    /**
     * Get event datetime information
     * 
     * @param int $product_id Product ID
     * @return array Event datetime data
     */
    public function getEventDateTime(int $product_id): array {
        $start_datetime = get_post_meta($product_id, '_ui_event_start_datetime', true);
        $end_datetime = get_post_meta($product_id, '_ui_event_end_datetime', true);
        
        $result = [
            'start_timestamp' => $start_datetime,
            'end_timestamp' => $end_datetime,
            'start_formatted' => '',
            'end_formatted' => '',
            'duration' => 0
        ];
        
        if (!empty($start_datetime) && is_numeric($start_datetime)) {
            $result['start_formatted'] = date('F j, Y g:i A', $start_datetime);
        }
        
        if (!empty($end_datetime) && is_numeric($end_datetime)) {
            $result['end_formatted'] = date('F j, Y g:i A', $end_datetime);
            
            if (!empty($start_datetime) && is_numeric($start_datetime)) {
                $result['duration'] = $end_datetime - $start_datetime;
            }
        }
        
        return $result;
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
            'dependencies_met' => class_exists('WC_Order') && class_exists('LGL_API')
        ];
    }
    
    /**
     * Get required product categories
     * 
     * @return array<string>
     */
    public function getRequiredCategories(): array {
        return ['events'];
    }
    
    /**
     * Get required meta fields
     * 
     * @return array<string>
     */
    public function getRequiredMetaFields(): array {
        return [
            '_ui_lgl_sync_id', // Unified LGL fund ID (preferred)
            '_ui_event_lgl_fund_id', // Legacy field (fallback)
            '_ui_event_start_datetime'
        ];
    }
}
