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

use Exception;
use UpstateInternational\LGL\LGL\ApiSettings;
use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;
use UpstateInternational\LGL\Memberships\MembershipRegistrationService;

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
     * Membership registration service
     *
     * @var MembershipRegistrationService
     */
    private MembershipRegistrationService $registrationService;

    /**
     * API settings service
     *
     * @var ApiSettings
     */
    private ApiSettings $apiSettings;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param WpUsers $wpUsers LGL WP Users service
     */
    public function __construct(
        Helper $helper,
        WpUsers $wpUsers,
        MembershipRegistrationService $registrationService,
        ApiSettings $apiSettings
    ) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
        $this->registrationService = $registrationService;
        $this->apiSettings = $apiSettings;
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
        $this->helper->debug('🎯 MembershipOrderHandler::processOrder() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'membership_level' => $membership_level,
            'order_total' => $order->get_total(),
            'customer_email' => $order->get_billing_email(),
            'timestamp' => current_time('mysql')
        ]);
        
        // Log product details and determine actual membership level
        $product_id = $product->get_product_id();
        $variation_id = $product->get_variation_id() ?: $product_id;
        $product_price = (float) $product->get_total();
        $product_meta = get_post_meta($variation_id);
        
        $this->helper->debug('🛍️ MembershipOrderHandler: Product Details', [
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'product_name' => $product->get_name(),
            'product_total' => $product_price,
            'wc_name_passed' => $membership_level,
            'product_meta_keys' => array_keys($product_meta)
        ]);
        
        $membership_config = $this->resolveMembershipConfig($product, $membership_level, $product_price);
        $actual_membership_level = $membership_config['membership_name'] ?? $membership_level;
        $membership_level_id = $membership_config['membership_level_id'] ?? null;
        
        $this->helper->debug('🎯 MembershipOrderHandler: Membership Level Determination', [
            'wc_product_name' => $membership_level,
            'product_price' => $product_price,
            'determined_membership_level' => $actual_membership_level,
            'method' => 'price_based_detection'
        ]);
        
        try {
            // Update user role based on membership type (use actual membership level)
            $this->helper->debug('👤 MembershipOrderHandler: Updating user role...');
            $this->updateUserRole($uid, $actual_membership_level);
            
            // Prepare registration request (use actual membership level)
            $this->helper->debug('📋 MembershipOrderHandler: Building registration request...');
            $request = $this->buildRegistrationRequest(
                $uid,
                $order,
                $order_meta,
                $actual_membership_level,
                $membership_level_id
            );
            $this->helper->debug('📋 MembershipOrderHandler: Registration request built', $request);
            
            // Process user data updates
            $this->helper->debug('💾 MembershipOrderHandler: Processing user data updates...');
            $this->processUserDataUpdates($uid, $order, $order_meta, $request);
            
            // Register user in LGL
            $this->helper->debug('🔗 MembershipOrderHandler: Starting LGL registration...');
            $registrationResult = $this->registerUserInLGL(
                $uid,
                $order,
                $request,
                $membership_config
            );
            
            // Complete the order
            $this->helper->debug('✅ MembershipOrderHandler: Completing order...');
            $order->update_status('completed');
            
            $this->helper->debug('✅ MembershipOrderHandler::processOrder() COMPLETED SUCCESSFULLY', [
                'order_id' => $order->get_id(),
                'user_id' => $uid,
                'final_membership_level' => $actual_membership_level,
                'lgl_id' => $registrationResult['lgl_id'] ?? null,
                'match_method' => $registrationResult['match_method'] ?? null,
                'payment_id' => $registrationResult['payment_id'] ?? null
            ]);
            
        } catch (Exception $e) {
            $this->helper->debug('❌ MembershipOrderHandler::processOrder() FAILED', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'order_id' => $order->get_id(),
                'user_id' => $uid,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to maintain error handling
        }
    }
    
    /**
     * Determine actual membership level from product and price
     * 
     * @param mixed $product WooCommerce product item
     * @param string $wc_product_name WooCommerce product name
     * @param float $product_price Product price
     * @return string Actual membership level for LGL
     */
    private function resolveMembershipConfig($product, string $wc_product_name, float $product_price): array {
        $this->helper->debug('🔍 MembershipOrderHandler: Determining membership level', [
            'wc_product_name' => $wc_product_name,
            'product_price' => $product_price
        ]);
        $variation_id = $product->get_variation_id() ?: $product->get_product_id();
        $candidate_ids = [];
        $meta_value = get_post_meta($variation_id, '_lgl_membership_fund_id', true);
        if (!empty($meta_value)) {
            $candidate_ids[] = (int) $meta_value;
        }
        if ($variation_id !== $product->get_product_id()) {
            $parent_meta = get_post_meta($product->get_product_id(), '_lgl_membership_fund_id', true);
            if (!empty($parent_meta)) {
                $candidate_ids[] = (int) $parent_meta;
            }
        }
        $candidate_ids = array_values(array_unique(array_filter($candidate_ids)));
        foreach ($candidate_ids as $membership_level_id) {
            $config = $this->apiSettings->getMembershipLevelByLglId($membership_level_id);
            if ($config) {
                $membership_name = $config['level_name'] ?? $wc_product_name;
                $this->helper->debug('✅ MembershipOrderHandler: Using JetEngine membership mapping', [
                    'membership_level_id' => $membership_level_id,
                    'membership_name' => $membership_name
                ]);
                return [
                    'membership_level_id' => $membership_level_id,
                    'membership_name' => $membership_name,
                    'membership_config' => $config
                ];
            }
        }
        // Method 2: Try WooCommerce name conversion
        $name_based_level = $this->helper->uiMembershipWcNameToLgl($wc_product_name);
        if ($name_based_level !== $wc_product_name) { // If conversion happened
            $this->helper->debug('✅ MembershipOrderHandler: Using name-based detection', [
                'wc_name' => $wc_product_name,
                'lgl_name' => $name_based_level,
                'method' => 'name_based'
            ]);
            return [
                'membership_level_id' => null,
                'membership_name' => $name_based_level,
                'membership_config' => null
            ];
        }
        // Method 3: Check product variation attributes
        $variation_id = $product->get_variation_id();
        if ($variation_id) {
            $level_attribute = get_post_meta($variation_id, 'attribute_level', true);
            if (!empty($level_attribute)) {
                // Convert attribute to full membership name
                $attribute_mapping = [
                    'Individual' => 'Individual Membership',
                    'Family' => 'Family Membership',
                    'Patron' => 'Patron Membership',
                    'Patron Family' => 'Patron Family Membership',
                    'Daily' => 'Daily Plan'
                ];
                
                if (isset($attribute_mapping[$level_attribute])) {
                    $attribute_based_level = $attribute_mapping[$level_attribute];
                    $this->helper->debug('✅ MembershipOrderHandler: Using attribute-based detection', [
                        'attribute' => $level_attribute,
                        'membership_level' => $attribute_based_level,
                        'method' => 'attribute_based'
                    ]);
                    $slug = sanitize_title($attribute_based_level);
                    $config = $this->apiSettings->getMembershipLevel($slug) ?? null;
                    return [
                        'membership_level_id' => $config['lgl_membership_level_id'] ?? null,
                        'membership_name' => $attribute_based_level,
                        'membership_config' => $config
                    ];
                }
            }
        }
        
        // Fallback: Use original name but log warning
        $this->helper->debug('⚠️ MembershipOrderHandler: No membership level detection worked, using fallback', [
            'fallback_level' => $wc_product_name,
            'price_tried' => $product_price,
            'name_tried' => $wc_product_name,
            'method' => 'fallback'
        ]);
        
        return [
            'membership_level_id' => null,
            'membership_name' => $wc_product_name,
            'membership_config' => null
        ];
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
        string $membership_level,
        ?int $membership_level_id = null
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
            'lgl_membership_level_id' => $membership_level_id
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
        // Update user first/last name from order billing info
        if (!empty($request['user_firstname'])) {
            update_user_meta($uid, 'first_name', sanitize_text_field($request['user_firstname']));
        }
        if (!empty($request['user_lastname'])) {
            update_user_meta($uid, 'last_name', sanitize_text_field($request['user_lastname']));
        }
        
        // Update subscription information (if WooCommerce Subscriptions is active)
        if (method_exists($this->wpUsers, 'updateUserSubscriptionInfo')) {
            $this->wpUsers->updateUserSubscriptionInfo($uid, $order->get_id());
        }

        // Store membership level ID for MembershipRegistrationService to use
        if (!empty($request['lgl_membership_level_id'])) {
            update_user_meta($uid, 'lgl_membership_level_id', (int) $request['lgl_membership_level_id']);
        }
        
        $this->helper->debug('MembershipOrderHandler: User data updated (WordPress only, LGL sync happens separately)', $uid);
    }
    
    /**
     * Register user in LGL
     * 
     * @param array $request Registration request data
     * @return void
     */
    private function registerUserInLGL(
        int $userId,
        \WC_Order $order,
        array $request,
        array $membership_config
    ): array {
        $this->helper->debug('🔗 MembershipOrderHandler::registerUserInLGL() STARTED', [
            'user_id' => $userId,
            'user_email' => $request['user_email'] ?? 'N/A',
            'membership_type' => $request['ui-membership-type'] ?? 'N/A',
            'order_id' => $order->get_id(),
            'membership_level_id' => $request['lgl_membership_level_id'] ?? null
        ]);

        $user = get_userdata($userId);
        $emails = array_filter(array_unique(array_map('strtolower', array_filter([
            $request['user_email'] ?? null,
            $order->get_billing_email(),
            $user ? $user->user_email : null
        ]))));

        $context = [
            'user_id' => $userId,
            'search_name' => ($request['user_firstname'] ?? '') . '%20' . ($request['user_lastname'] ?? ''),
            'emails' => $emails,
            'email' => reset($emails) ?: ($request['user_email'] ?? ''),
            'order_id' => $order->get_id(),
            'price' => (float) $order->get_total(),
            'membership_level' => $request['ui-membership-type'] ?? '',
            'membership_level_id' => $request['lgl_membership_level_id'] ?? null,
            'payment_type' => $request['payment_method'] ?? $order->get_payment_method() ?? 'online',
            'is_family_member' => $this->isFamilyMembership($request['ui-membership-type'] ?? ''),
            'request' => $request,
            'membership_config' => $membership_config,
            'order' => $order
        ];

        $result = $this->registrationService->register($context);

        $order->update_meta_data('_lgl_sync_status', $result['status'] ?? 'unknown');
        $order->update_meta_data('_lgl_lgl_id', $result['lgl_id'] ?? null);
        $order->update_meta_data('_lgl_match_method', $result['match_method'] ?? null);
        if (isset($result['matched_email'])) {
            $order->update_meta_data('_lgl_matched_email', $result['matched_email']);
        }
        if (isset($result['payment_id'])) {
            $order->update_meta_data('_lgl_payment_id', $result['payment_id']);
        }
        if (isset($result['constituent_response'])) {
            $order->update_meta_data('_lgl_constituent_response', wp_json_encode($result['constituent_response']));
        }
        if (isset($result['payment_response'])) {
            $order->update_meta_data('_lgl_payment_response', wp_json_encode($result['payment_response']));
        }
        $order->save();

        $this->helper->debug('✅ MembershipOrderHandler::registerUserInLGL() COMPLETED', [
            'user_id' => $userId,
            'lgl_id' => $result['lgl_id'] ?? null,
            'status' => $result['status'] ?? 'unknown'
        ]);

        return $result;
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
