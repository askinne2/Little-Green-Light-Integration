<?php
/**
 * Membership Order Handler
 * 
 * Handles WooCommerce membership orders and processes them in LGL CRM.
 * Manages user role changes and membership registration.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;

/**
 * MembershipOrderHandler Class
 * 
 * Processes membership orders from WooCommerce
 */
class MembershipOrderHandler {
    
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
     * Process membership order
     * 
     * Handles membership orders from WooCommerce and registers them in LGL
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @param string $membership_level Membership level/product name
     * @return void
     */
    public function processOrder(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product,
        string $membership_level
    ): void {
        $this->helper->debug('MembershipOrderHandler: Processing membership order', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'membership_level' => $membership_level
        ]);
        
        // Update user role based on membership type
        $this->updateUserRole($uid, $membership_level);
        
        // Prepare registration request
        $request = $this->buildRegistrationRequest($uid, $order, $order_meta, $membership_level);
        
        // Process user data updates
        $this->processUserDataUpdates($uid, $order, $order_meta, $request);
        
        // Register user in LGL
        $this->registerUserInLGL($request);
        
        // Complete the order
        $order->update_status('completed');
        
        $this->helper->debug('MembershipOrderHandler: Membership order completed', $order->get_id());
    }
    
    /**
     * Update user role based on membership type
     * 
     * @param int $uid User ID
     * @param string $membership_level Membership level
     * @return void
     */
    private function updateUserRole(int $uid, string $membership_level): void {
        if (stripos($membership_level, 'family') !== false) {
            $this->helper->changeUserRole($uid, 'customer', 'ui_patron_owner');
            $this->helper->debug('MembershipOrderHandler: Updated user role to ui_patron_owner (family)', $uid);
        } else {
            $this->helper->changeUserRole($uid, 'customer', 'ui_member');
            $this->helper->debug('MembershipOrderHandler: Updated user role to ui_member (individual)', $uid);
        }
    }
    
    /**
     * Build registration request data
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param string $membership_level Membership level
     * @return array Registration request data
     */
    private function buildRegistrationRequest(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        string $membership_level
    ): array {
        $order_created_date = $order->get_date_created();
        $user_data = get_userdata($uid);
        $lgl_membership_name = $this->helper->uiMembershipWcNameToLgl($membership_level);
        
        return [
            'user_firstname' => $order->get_billing_first_name(),
            'user_lastname' => $order->get_billing_last_name(),
            'user_company' => $order->get_billing_company(),
            'username' => $user_data->user_login,
            'user_email' => $order->get_billing_email(),
            'user_phone' => $order->get_billing_phone(),
            'user-address-1' => $order->get_billing_address_1(),
            'user-address-2' => $order->get_billing_address_2(),
            'user-city' => $order->get_billing_city(),
            'user-state' => $order->get_billing_state(),
            'user-postal-code' => $order->get_billing_postcode(),
            'user-country-of-origin' => $order_meta['country'] ?? '',
            'current_date' => $order_created_date->getTimestamp(),
            'price' => $order->get_total(),
            'ui-membership-type' => $lgl_membership_name,
            'inserted_post_id' => $order->get_id(),
            'user_id' => $uid,
        ];
    }
    
    /**
     * Process user data updates
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param array $request Registration request data
     * @return void
     */
    private function processUserDataUpdates(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        array $request
    ): void {
        // Update user data in WordPress
        $this->wpUsers->updateUserData($request, $order, $order_meta);
        
        // Update subscription information
        $this->wpUsers->updateUserSubscriptionInfo($uid, $order->get_id());
        
        $this->helper->debug('MembershipOrderHandler: User data updated', $uid);
    }
    
    /**
     * Register user in LGL
     * 
     * @param array $request Registration request data
     * @return void
     */
    private function registerUserInLGL(array $request): void {
        // Use legacy LGL_API for now - this will be modernized in future phases
        if (class_exists('LGL_API')) {
            $lgl_api = \LGL_API::get_instance();
            $lgl_api->lgl_register_user($request, []);
            $this->helper->debug('MembershipOrderHandler: User registered in LGL', $request['user_id']);
        } else {
            $this->helper->debug('MembershipOrderHandler: LGL_API not available for user registration');
        }
    }
    
    /**
     * Validate membership order
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param string $membership_level Membership level
     * @return array Validation result
     */
    public function validateOrder(int $uid, \WC_Order $order, string $membership_level): array {
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
        
        // Validate membership level
        if (empty($membership_level)) {
            $result['errors'][] = 'Membership level is required';
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
        
        $result['valid'] = empty($result['errors']);
        
        return $result;
    }
    
    /**
     * Get supported membership types
     * 
     * @return array<string>
     */
    public function getSupportedMembershipTypes(): array {
        return [
            'Individual Membership',
            'Family Membership',
            'Patron Membership',
            'Patron Family Membership'
        ];
    }
    
    /**
     * Check if membership type is family type
     * 
     * @param string $membership_level Membership level
     * @return bool
     */
    public function isFamilyMembership(string $membership_level): bool {
        return stripos($membership_level, 'family') !== false;
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
            'supported_membership_types' => $this->getSupportedMembershipTypes(),
            'dependencies_met' => class_exists('WC_Order') && class_exists('LGL_API')
        ];
    }
}
