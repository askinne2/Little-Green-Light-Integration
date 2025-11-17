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
use UpstateInternational\LGL\Memberships\RenewalStrategyManager;

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
     * Renewal strategy manager
     *
     * @var RenewalStrategyManager|null
     */
    private ?RenewalStrategyManager $strategyManager = null;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param WpUsers $wpUsers LGL WP Users service
     * @param MembershipRegistrationService $registrationService Membership registration service
     * @param ApiSettings $apiSettings API settings service
     * @param RenewalStrategyManager|null $strategyManager Renewal strategy manager (optional)
     */
    public function __construct(
        Helper $helper,
        WpUsers $wpUsers,
        MembershipRegistrationService $registrationService,
        ApiSettings $apiSettings,
        ?RenewalStrategyManager $strategyManager = null
    ) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
        $this->registrationService = $registrationService;
        $this->apiSettings = $apiSettings;
        $this->strategyManager = $strategyManager;
    }
    
    /**
     * Process membership order (immediate tasks only)
     * 
     * Handles WordPress operations without LGL API calls
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @param string $membership_level Membership level/product name
     * @return void
     */
    public function processOrderImmediate(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product,
        string $membership_level
    ): void {
        $this->helper->debug('⚡ MembershipOrderHandler::processOrderImmediate() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'membership_level' => $membership_level,
            'mode' => 'immediate_only'
        ]);
        
        try {
            // Resolve membership configuration
            $membership_config = $this->resolveMembershipConfig($product, $membership_level, (float) $product->get_total());
            $actual_membership_level = $membership_config['membership_name'] ?? $membership_level;
            $membership_level_id = $membership_config['membership_level_id'] ?? null;
            
            // Update user role based on membership type
            $this->updateUserRole($uid, $actual_membership_level);
            
            // Process family slot purchases (token-based system)
            $this->processFamilySlots($order, $uid);
            
            // Prepare registration request
            $request = $this->buildRegistrationRequest(
                $uid,
                $order,
                $order_meta,
                $actual_membership_level,
                $membership_level_id
            );
            
            // Process user data updates (save to WordPress only - skip LGL sync)
            $this->processUserDataUpdates($uid, $order, $order_meta, $request);
            
            // Complete the order
            $order->update_status('completed');
            
            // Set renewal date for one-time purchases
            $this->setRenewalDateForOneTimePurchase($uid, $actual_membership_level);
            
            $this->helper->debug('✅ MembershipOrderHandler::processOrderImmediate() COMPLETED', [
                'order_id' => $order->get_id(),
                'user_id' => $uid,
                'final_membership_level' => $actual_membership_level
            ]);
            
        } catch (Exception $e) {
            $this->helper->debug('❌ MembershipOrderHandler::processOrderImmediate() FAILED', [
                'error' => $e->getMessage(),
                'order_id' => $order->get_id(),
                'user_id' => $uid
            ]);
            throw $e;
        }
    }
    
    /**
     * Process membership order (LGL sync only)
     * 
     * Handles LGL API calls for background processing
     * 
     * @param int $uid User ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param mixed $product Product item
     * @param string $membership_level Membership level/product name
     * @return void
     */
    public function processOrderLglSync(
        int $uid,
        \WC_Order $order,
        array $order_meta,
        $product,
        string $membership_level
    ): void {
        $this->helper->debug('🔄 MembershipOrderHandler::processOrderLglSync() STARTED', [
            'user_id' => $uid,
            'order_id' => $order->get_id(),
            'membership_level' => $membership_level,
            'mode' => 'lgl_sync_only'
        ]);
        
        try {
            // Resolve membership configuration
            $membership_config = $this->resolveMembershipConfig($product, $membership_level, (float) $product->get_total());
            $actual_membership_level = $membership_config['membership_name'] ?? $membership_level;
            $membership_level_id = $membership_config['membership_level_id'] ?? null;
            
            // Prepare registration request
            $request = $this->buildRegistrationRequest(
                $uid,
                $order,
                $order_meta,
                $actual_membership_level,
                $membership_level_id
            );
            
            // Register user in LGL (API calls only)
            $registrationResult = $this->registerUserInLGL(
                $uid,
                $order,
                $request,
                $membership_config
            );
            
            $this->helper->debug('✅ MembershipOrderHandler::processOrderLglSync() COMPLETED', [
                'order_id' => $order->get_id(),
                'user_id' => $uid,
                'lgl_id' => $registrationResult['lgl_id'] ?? null,
                'match_method' => $registrationResult['match_method'] ?? null,
                'payment_id' => $registrationResult['payment_id'] ?? null
            ]);
            
        } catch (Exception $e) {
            $this->helper->debug('❌ MembershipOrderHandler::processOrderLglSync() FAILED', [
                'error' => $e->getMessage(),
                'order_id' => $order->get_id(),
                'user_id' => $uid
            ]);
            throw $e;
        }
    }
    
    /**
     * Process membership order (legacy - full sync)
     * 
     * @deprecated Use processOrderImmediate() + processOrderLglSync() instead
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
            
            // Process family slot purchases (token-based system)
            $this->helper->debug('🎟️ MembershipOrderHandler: Processing family slot purchases...');
            $this->processFamilySlots($order, $uid);
            
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
            
            // Set renewal date for one-time purchases
            $this->setRenewalDateForOneTimePurchase($uid, $actual_membership_level);
            
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
        
        // Check for unified LGL Sync ID field first (new standard)
        $meta_value = get_post_meta($variation_id, '_ui_lgl_sync_id', true);
        if (empty($meta_value)) {
            // Fallback to legacy membership-specific field
            $meta_value = get_post_meta($variation_id, '_lgl_membership_fund_id', true);
        }
        
        if (!empty($meta_value)) {
            $candidate_ids[] = (int) $meta_value;
            $this->helper->debug('🔍 Found LGL sync ID on variation', [
                'variation_id' => $variation_id,
                'fund_id' => (int) $meta_value,
                'source' => get_post_meta($variation_id, '_ui_lgl_sync_id', true) ? 'unified' : 'legacy'
            ]);
        }
        
        // Check parent product if this is a variation
        if ($variation_id !== $product->get_product_id()) {
            $parent_meta = get_post_meta($product->get_product_id(), '_ui_lgl_sync_id', true);
            if (empty($parent_meta)) {
                // Fallback to legacy field on parent
                $parent_meta = get_post_meta($product->get_product_id(), '_lgl_membership_fund_id', true);
            }
            
            if (!empty($parent_meta)) {
                $candidate_ids[] = (int) $parent_meta;
                $this->helper->debug('🔍 Found LGL sync ID on parent product', [
                    'parent_id' => $product->get_product_id(),
                    'fund_id' => (int) $parent_meta,
                    'source' => get_post_meta($product->get_product_id(), '_ui_lgl_sync_id', true) ? 'unified' : 'legacy'
                ]);
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
                // Convert attribute to membership name
                $attribute_mapping = [
                    // New membership model (2024+)
                    'Member' => 'Member',
                    'Supporter' => 'Supporter',
                    'Patron' => 'Patron',
                    'Family Member' => 'Family Member',
                    
                    // Legacy membership model (for backwards compatibility)
                    'Individual' => 'Individual Membership',
                    'Family' => 'Family Membership',
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
        // Use WpUsers::updateUserData() to save ALL billing address fields to user meta
        // This ensures address data from checkout is properly saved for guest checkouts
        // Skip ALL LGL sync during immediate processing - LGL sync happens separately in async processing via registerUserInLGL()
        $this->wpUsers->updateUserData($request, $order, $order_meta, true, true);
        
        $this->helper->debug('MembershipOrderHandler: User data updated (WordPress only, LGL sync happens separately)', $uid);

        // Store membership level ID for MembershipRegistrationService to use
        if (!empty($request['lgl_membership_level_id'])) {
            update_user_meta($uid, 'lgl_membership_level_id', (int) $request['lgl_membership_level_id']);
        }
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
            // New one-time membership model (2024+)
            'Member',
            'Supporter',
            'Patron',
            'Family Member',
            
            // Legacy subscription model (for backwards compatibility)
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
    
    /**
     * Set renewal date for one-time membership purchases
     * 
     * Only sets renewal date if user doesn't have active WC subscription.
     * Enables plugin-managed renewal reminders for one-time purchases.
     * 
     * @param int $user_id User ID
     * @param string $membership_level Membership level
     * @return void
     */
    private function setRenewalDateForOneTimePurchase(int $user_id, string $membership_level): void {
        // Check if strategy manager is available
        if (!$this->strategyManager) {
            $this->helper->debug("RenewalStrategyManager not available, skipping renewal date setup for user {$user_id}");
            return;
        }
        
        // Only set renewal date if user doesn't have active WC subscription
        if (!$this->strategyManager->userHasActiveSubscription($user_id)) {
            // Set renewal date to 1 year from now
            $renewal_date = strtotime('+1 year');
            update_user_meta($user_id, 'user-membership-renewal-date', $renewal_date);
            update_user_meta($user_id, 'user-membership-start-date', current_time('timestamp'));
            update_user_meta($user_id, 'user-subscription-status', 'one-time');
            
            $this->helper->debug("Set one-time renewal date for user {$user_id}", [
                'membership_level' => $membership_level,
                'renewal_date' => date('Y-m-d', $renewal_date),
                'start_date' => date('Y-m-d', current_time('timestamp'))
            ]);
        } else {
            // User has active subscription - mark as WC-managed
            update_user_meta($user_id, 'user-subscription-status', 'wc-subscription');
            $this->helper->debug("User {$user_id} has active WC subscription, renewal managed by WooCommerce");
        }
    }
    
    /**
     * Process family member slot purchases
     * Updates user_available_family_slots and user_total_family_slots_purchased
     * Assigns ui_patron_owner role when slots are purchased
     * 
     * @param \WC_Order $order WooCommerce order
     * @param int $uid User ID
     * @return void
     */
    private function processFamilySlots(\WC_Order $order, int $uid): void {
        $family_product_id = 89889; // Family Member product
        $family_sku = 'UIFAMILYMEMBER25';
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);
            
            if (!$product) {
                continue;
            }
            
            // Check if this is the family member slot product
            if ($product_id === $family_product_id || $product->get_sku() === $family_sku) {
                $qty = (int) $item->get_quantity();
                
                // Track total purchased for reporting
                $total_purchased = (int) get_user_meta($uid, 'user_total_family_slots_purchased', true);
                $new_total = $total_purchased + $qty;
                update_user_meta($uid, 'user_total_family_slots_purchased', $new_total);
                
                // Sync user_used_family_slots with actual JetEngine count (source of truth)
                $this->helper->syncUsedFamilySlotsMeta($uid);
                
                // Recalculate available slots based on actual count: total_purchased - actual_used
                $actual_used = $this->helper->getActualUsedFamilySlots($uid);
                $new_available = $new_total - $actual_used;
                update_user_meta($uid, 'user_available_family_slots', max(0, $new_available));
                
                // Assign ui_patron_owner role if not already assigned
                // Note: Role slug is ui_patron_owner (display name: "UI Family Owner")
                $user = new \WP_User($uid);
                if (!in_array('ui_patron_owner', $user->roles)) {
                    // If user is ui_member, upgrade to ui_patron_owner
                    if (in_array('ui_member', $user->roles)) {
                        $this->helper->changeUserRole($uid, 'ui_member', 'ui_patron_owner');
                        $this->helper->debug('MembershipOrderHandler: Promoted user to ui_patron_owner', [
                            'user_id' => $uid,
                            'available_slots' => $new_available
                        ]);
                    }
                }
                
                $actual_used = $this->helper->getActualUsedFamilySlots($uid);
                
                $this->helper->debug('MembershipOrderHandler: Added family slots', [
                    'user_id' => $uid,
                    'order_id' => $order->get_id(),
                    'purchased_qty' => $qty,
                    'new_available' => $new_available,
                    'new_total_purchased' => $new_total,
                    'actual_used_from_jetengine' => $actual_used,
                    'note' => 'user_used_family_slots synced with JetEngine count'
                ]);
            }
        }
    }
}
