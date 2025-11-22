<?php
/**
 * Cart Validator
 * 
 * Validates WooCommerce cart contents for membership and family member products.
 * Handles mixed cart scenarios and enforces business rules.
 * 
 * @package UpstateInternational\LGL\WooCommerce
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;

/**
 * CartValidator Class
 * 
 * Validates cart contents before checkout
 */
class CartValidator {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Settings manager (lazy-loaded)
     * 
     * @var mixed|null
     */
    private $settingsManager = null;
    
    /**
     * Membership category slug
     */
    const MEMBERSHIP_CATEGORY = 'memberships';
    
    /**
     * Family member product name identifier
     */
    const FAMILY_MEMBER_NAME = 'Family Member';
    
    /**
     * Maximum family members allowed per membership (fallback default)
     */
    const MAX_FAMILY_MEMBERS = 6;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     */
    public function __construct(Helper $helper) {
        $this->helper = $helper;
        $this->registerHooks();
    }
    
    /**
     * Get settings manager instance
     * 
     * @return mixed|null
     */
    private function getSettingsManager() {
        if ($this->settingsManager === null && function_exists('lgl_get_container')) {
            try {
                $container = lgl_get_container();
                if ($container->has('admin.settings_manager')) {
                    $this->settingsManager = $container->get('admin.settings_manager');
                }
            } catch (\Exception $e) {
                // SettingsManager not available, use defaults
            }
        }
        return $this->settingsManager;
    }
    
    /**
     * Get cart validation setting
     * 
     * @param string $key Setting key
     * @param mixed $default Default value if setting not found
     * @return mixed
     */
    private function getCartSetting(string $key, $default = null) {
        $settingsManager = $this->getSettingsManager();
        if ($settingsManager) {
            $cartValidation = $settingsManager->get('cart_validation', []);
            return $cartValidation[$key] ?? $default;
        }
        return $default;
    }
    
    /**
     * Get max family members (from settings or constant)
     * 
     * @return int
     */
    private function getMaxFamilyMembers(): int {
        return (int) $this->getCartSetting('max_family_members', self::MAX_FAMILY_MEMBERS);
    }
    
    /**
     * Check if membership required for family members
     * 
     * @return bool
     */
    private function requiresMembershipForFamilyMembers(): bool {
        return (bool) $this->getCartSetting('require_membership_for_family_members', true);
    }
    
    /**
     * Check if guests can purchase family members
     * 
     * @return bool
     */
    private function allowGuestFamilyMemberPurchase(): bool {
        return (bool) $this->getCartSetting('allow_guest_family_member_purchase', false);
    }
    
    /**
     * Check if cart contains a membership product
     * 
     * @return bool
     */
    private function cartHasMembershipProduct(): bool {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return false;
        }
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($this->isMembershipProduct($cart_item['data'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Register WooCommerce validation hooks
     * 
     * @return void
     */
    private function registerHooks(): void {
        // Validate when adding to cart
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAddToCart'], 10, 5);
        
        // Validate cart contents before checkout
        add_action('woocommerce_check_cart_items', [$this, 'validateCartContents']);
        
        // Validate cart item quantity updates
        add_filter('woocommerce_update_cart_validation', [$this, 'validateCartUpdate'], 10, 4);
    }
    
    /**
     * Validate product being added to cart
     * 
     * @param bool $passed Validation result
     * @param int $product_id Product ID being added
     * @param int $quantity Quantity being added
     * @param int $variation_id Variation ID (if applicable)
     * @param array $variations Variation data
     * @return bool
     */
    public function validateAddToCart($passed, $product_id, $quantity, $variation_id = 0, $variations = []): bool {
        if (!$passed) {
            return false; // Already failed validation
        }
        
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return $passed;
        }
        
        // Check if this is a family member product
        if ($this->isFamilyMemberProduct($product)) {
            // Guest users must have membership in cart (if required)
            if (!is_user_logged_in()) {
                $requires_membership = $this->requiresMembershipForFamilyMembers();
                $allow_guest = $this->allowGuestFamilyMemberPurchase();
                
                if ($requires_membership && !$allow_guest) {
                    $has_membership = $this->cartHasMembershipProduct();
                    
                    if (!$has_membership) {
                        wc_add_notice(
                            'Family Member slots can only be purchased with a membership. Please add a membership (Member, Supporter, or Patron) to your cart first, or log in to your account if you already have a membership.',
                            'error'
                        );
                        return false;
                    }
                }
            }
            
            return $this->validateFamilyMemberQuantity($product, $quantity, $product_id, $variation_id);
        }
        
        // Check if this is a membership product (Member, Supporter, Patron)
        if ($this->isMembershipProduct($product)) {
            return $this->validateMembershipProduct($product, $quantity);
        }
        
        return $passed;
    }
    
    /**
     * Validate cart contents before checkout
     * 
     * @return void
     */
    public function validateCartContents(): void {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        
        $cart_items = WC()->cart->get_cart();
        $family_member_count = 0;
        $membership_count = 0;
        $membership_products = [];
        
        // Count family member and membership products in cart
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            if ($this->isFamilyMemberProduct($product)) {
                $family_member_count += $cart_item['quantity'];
            } elseif ($this->isMembershipProduct($product)) {
                $membership_count += $cart_item['quantity'];
                $membership_products[] = $product->get_name();
            }
        }
        
        // CRITICAL FIX: Prevent multiple different membership products in cart
        // Users should only have ONE active membership at a time
        if (count($membership_products) > 1) {
            $unique_memberships = array_unique($membership_products);
            $membership_list = implode('", "', $unique_memberships);
            wc_add_notice(
                sprintf(
                    'You have multiple membership products in your cart ("%s"). You can only purchase one membership at a time. Please remove all but one membership from your cart before proceeding to checkout.',
                    $membership_list
                ),
                'error'
            );
            return;
        }
        
        // Guest + Family Member validation
        if ($family_member_count > 0 && !is_user_logged_in()) {
            $requires_membership = $this->requiresMembershipForFamilyMembers();
            $allow_guest = $this->allowGuestFamilyMemberPurchase();
            
            if ($requires_membership && !$allow_guest && $membership_count === 0) {
                wc_add_notice(
                    'Family Member slots can only be purchased with a membership. Please add a membership (Member, Supporter, or Patron) to your cart, or log in to your account if you already have a membership.',
                    'error'
                );
                return;
            }
        }
        
        // Validate family member quantity
        if ($family_member_count > 0) {
            $this->validateTotalFamilyMemberQuantity($family_member_count);
        }
    }
    
    /**
     * Validate cart item quantity update
     * 
     * @param bool $passed Validation result
     * @param string $cart_item_key Cart item key
     * @param array $values Cart item values
     * @param int $quantity New quantity
     * @return bool
     */
    public function validateCartUpdate($passed, $cart_item_key, $values, $quantity): bool {
        if (!$passed) {
            return false;
        }
        
        $product = $values['data'];
        if (!$product) {
            return $passed;
        }
        
        // If updating a family member product quantity
        if ($this->isFamilyMemberProduct($product)) {
            // Recalculate total family member quantity in cart
            $cart_items = WC()->cart->get_cart();
            $total_family_qty = 0;
            
            foreach ($cart_items as $key => $item) {
                if ($this->isFamilyMemberProduct($item['data'])) {
                    if ($key === $cart_item_key) {
                        $total_family_qty += $quantity; // Use new quantity
                    } else {
                        $total_family_qty += $item['quantity']; // Use existing quantity
                    }
                }
            }
            
            return $this->validateTotalFamilyMemberQuantity($total_family_qty);
        }
        
        return $passed;
    }
    
    /**
     * Check if product is a family member product
     * 
     * @param \WC_Product $product Product object
     * @return bool
     */
    private function isFamilyMemberProduct($product): bool {
        $parent_id = $product->get_parent_id() ?: $product->get_id();
        
        // Must be in memberships category
        if (!has_term(self::MEMBERSHIP_CATEGORY, 'product_cat', $parent_id)) {
            return false;
        }
        
        // Check product name contains "Family Member"
        $product_name = $product->get_name();
        return stripos($product_name, self::FAMILY_MEMBER_NAME) !== false;
    }
    
    /**
     * Check if product is a membership product
     * 
     * Recognizes both new membership products (Gateway Member, Crossroads Collective, World Horizon Patron)
     * and legacy products (Member, Supporter, Patron, or containing "membership")
     * 
     * @param \WC_Product $product Product object
     * @return bool
     */
    private function isMembershipProduct($product): bool {
        $parent_id = $product->get_parent_id() ?: $product->get_id();
        
        // Must be in memberships category
        if (!has_term(self::MEMBERSHIP_CATEGORY, 'product_cat', $parent_id)) {
            return false;
        }
        
        // Check if it's NOT a family member product
        if ($this->isFamilyMemberProduct($product)) {
            return false;
        }
        
        // New membership product names (2025+ model)
        $product_name = $product->get_name();
        $new_membership_names = [
            'Gateway Member',
            'Crossroads Collective',
            'World Horizon Patron',
            // Legacy names (for backward compatibility)
            'Member',
            'Supporter',
            'Patron'
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
        // This ensures any product in the memberships category is validated
        return true;
    }
    
    /**
     * Validate family member product quantity being added
     * 
     * @param \WC_Product $product Product object
     * @param int $quantity Quantity being added
     * @param int $product_id Product ID
     * @param int $variation_id Variation ID
     * @return bool
     */
    private function validateFamilyMemberQuantity($product, int $quantity, int $product_id, int $variation_id): bool {
        // Get current cart family member quantity
        $cart_family_qty = $this->getCartFamilyMemberQuantity();
        
        // Get existing family slots (if user is logged in)
        $existing_used = 0;
        $existing_purchased = 0;
        
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $existing_used = $this->helper->getActualUsedFamilySlots($user_id);
            $existing_purchased = (int) get_user_meta($user_id, 'user_total_family_slots_purchased', true);
        }
        
        $max_allowed = $this->getMaxFamilyMembers();
        
        // Check if user has already reached the maximum number of family members
        if ($existing_used >= $max_allowed) {
            wc_add_notice(
                sprintf(
                    'You have reached the maximum limit of %d family members. Please remove a family member before purchasing additional slots.',
                    $max_allowed
                ),
                'error'
            );
            return false;
        }
        
        // Calculate effective purchased slots (capped at max_allowed)
        // User may have purchased more than max, but can only use max
        $effective_purchased = min($existing_purchased, $max_allowed);
        
        // Calculate how many slots are effectively available (considering both used and purchased)
        // This accounts for users who already purchased more slots than they can use
        $effective_available = $effective_purchased - $existing_used;
        
        // Calculate total slots after adding this quantity (including what's already in cart)
        $total_after_add = $effective_purchased + $cart_family_qty + $quantity;
        
        // Calculate how many more slots can be purchased based on:
        // 1. Maximum allowed (6)
        // 2. Already purchased (capped at 6)
        // 3. Already used
        $remaining_slots = $max_allowed - max($existing_used, $effective_purchased);
        
        // If user already has max_allowed or more purchased slots, they can't purchase more
        if ($effective_purchased >= $max_allowed) {
            wc_add_notice(
                sprintf(
                    'You have already purchased %d family member slot(s), which is the maximum allowed. You currently have %d family member(s) added. You cannot purchase additional slots until you add more family members or remove existing ones.',
                    $effective_purchased,
                    $existing_used
                ),
                'error'
            );
            return false;
        }
        
        // Check if adding this quantity would exceed the limit
        $total_requested = $cart_family_qty + $quantity;
        
        if ($total_requested > $remaining_slots) {
            $can_add = max(0, $remaining_slots - $cart_family_qty);
            
            if ($can_add <= 0) {
                wc_add_notice(
                    sprintf(
                        'You can only purchase up to %d more family member slot(s). You currently have %d purchased slot(s) (%d used, %d available) and the maximum is %d.',
                        $remaining_slots,
                        $effective_purchased,
                        $existing_used,
                        $effective_available,
                        $max_allowed
                    ),
                    'error'
                );
            } else {
                wc_add_notice(
                    sprintf(
                        'You can only add %d more family member slot(s) to your cart. You currently have %d purchased slot(s) (%d used), %d slot(s) in cart, and the maximum is %d.',
                        $can_add,
                        $effective_purchased,
                        $existing_used,
                        $cart_family_qty,
                        $max_allowed
                    ),
                    'error'
                );
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate total family member quantity in cart
     * 
     * @param int $total_quantity Total family member quantity in cart
     * @return bool
     */
    private function validateTotalFamilyMemberQuantity(int $total_quantity): bool {
        $max_allowed = $this->getMaxFamilyMembers();
        
        // Get existing family slots (if user is logged in)
        $existing_used = 0;
        $existing_purchased = 0;
        
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $existing_used = $this->helper->getActualUsedFamilySlots($user_id);
            $existing_purchased = (int) get_user_meta($user_id, 'user_total_family_slots_purchased', true);
        }
        
        // Calculate effective purchased slots (capped at max_allowed)
        $effective_purchased = min($existing_purchased, $max_allowed);
        
        // Calculate remaining slots user can purchase
        // If they already have max_allowed or more purchased, they can't purchase more
        $remaining_slots = $max_allowed - max($existing_used, $effective_purchased);
        
        if ($effective_purchased >= $max_allowed) {
            wc_add_notice(
                sprintf(
                    'You have already purchased %d family member slot(s), which is the maximum allowed. You currently have %d family member(s) added. You cannot purchase additional slots until you add more family members or remove existing ones.',
                    $effective_purchased,
                    $existing_used
                ),
                'error'
            );
            return false;
        }
        
        if ($total_quantity > $remaining_slots) {
            $effective_available = $effective_purchased - $existing_used;
            wc_add_notice(
                sprintf(
                    'Your cart contains %d family member slot(s), but you can only purchase %d more. You currently have %d purchased slot(s) (%d used, %d available) and the maximum is %d. Please reduce the quantity in your cart.',
                    $total_quantity,
                    $remaining_slots,
                    $effective_purchased,
                    $existing_used,
                    $effective_available,
                    $max_allowed
                ),
                'error'
            );
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate membership product (Member, Supporter, Patron)
     * 
     * Note: WooCommerce settings should enforce max quantity of 1 per product,
     * but we validate here as a safety check.
     * 
     * @param \WC_Product $product Product object
     * @param int $quantity Quantity being added
     * @return bool
     */
    private function validateMembershipProduct($product, int $quantity): bool {
        // Membership products should only be added one at a time
        // This is typically handled by WooCommerce product settings,
        // but we validate here as a safety check
        if ($quantity > 1) {
            wc_add_notice(
                sprintf(
                    'You can only add one "%s" membership at a time.',
                    $product->get_name()
                ),
                'error'
            );
            return false;
        }
        
        // CRITICAL FIX: Check if user already has ANY membership product in cart
        // Users should only have ONE active membership at a time (not multiple different types)
        if (!function_exists('WC') || !WC()->cart) {
            return true; // Can't validate without cart
        }
        
        $cart_items = WC()->cart->get_cart();
        $product_name = $product->get_name();
        
        foreach ($cart_items as $cart_item) {
            $cart_product = $cart_item['data'];
            if ($this->isMembershipProduct($cart_product)) {
                $cart_product_name = $cart_product->get_name();
                
                // Prevent adding ANY other membership if one already exists
                // This prevents "Member" + "Supporter" or any other combination
                if ($cart_product_name !== $product_name) {
                    wc_add_notice(
                        sprintf(
                            'You already have a "%s" membership in your cart. You can only purchase one membership at a time. Please remove the existing membership from your cart before adding a different one.',
                            $cart_product_name
                        ),
                        'error'
                    );
                    return false;
                }
                
                // Also prevent duplicate same-type memberships (existing check)
                if ($cart_product_name === $product_name) {
                    wc_add_notice(
                        sprintf(
                            'You already have a "%s" membership in your cart.',
                            $product_name
                        ),
                        'error'
                    );
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Get total family member quantity in cart
     * 
     * @return int
     */
    private function getCartFamilyMemberQuantity(): int {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return 0;
        }
        
        $total = 0;
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($this->isFamilyMemberProduct($cart_item['data'])) {
                $total += $cart_item['quantity'];
            }
        }
        
        return $total;
    }
}

