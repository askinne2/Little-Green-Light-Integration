<?php
/**
 * LGL Constituents Manager
 * 
 * Manages constituent data, creation, and updates in Little Green Light.
 * Handles personal information, addresses, memberships, and custom fields.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

use UpstateInternational\LGL\Core\CacheManager;

/**
 * Constituents Class
 * 
 * Manages LGL constituent data and operations
 */
class Constituents {
    
    /**
     * Class instance
     * 
     * @var Constituents|null
     */
    private static $instance = null;
    
    /**
     * Personal data
     * 
     * @var array
     */
    private $personalData = [];
    
    /**
     * Email data
     * 
     * @var array
     */
    private $emailData = [];
    
    /**
     * Phone data
     * 
     * @var array
     */
    private $phoneData = [];
    
    /**
     * Address data
     * 
     * @var array
     */
    private $addressData = [];
    
    /**
     * Category data
     * 
     * @var array
     */
    private $categoryData = [];
    
    /**
     * Groups data
     * 
     * @var array
     */
    private $groupsData = [];
    
    /**
     * Custom data
     * 
     * @var array
     */
    private $customData = [];
    
    /**
     * Membership data
     * 
     * @var array
     */
    private $membershipData = [];
    
    /**
     * API Settings instance
     * 
     * @var ApiSettings
     */
    private $lgl;
    
    /**
     * Data removal flags
     */
    private $removePreviousEmailAddresses = false;
    private $removePreviousPhoneNumbers = false;
    private $removePreviousStreetAddresses = false;
    private $removePreviousWebAddresses = false;
    
    /**
     * Debug mode flag
     */
    const DEBUG_MODE = true;
    
    /**
     * Get instance
     * 
     * @return Constituents
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
        $this->initializeConstituent();
    }
    
    /**
     * Initialize constituent data structures
     */
    private function initializeConstituent(): void {
        $this->resetAllData();
        // error_log('LGL Constituents: Initialized successfully');
    }
    
    /**
     * Reset all data arrays
     */
    private function resetAllData(): void {
        $this->personalData = [];
        $this->emailData = [];
        $this->phoneData = [];
        $this->addressData = [];
        $this->categoryData = [];
        $this->groupsData = [];
        $this->customData = [];
        $this->membershipData = [];
    }
    
    /**
     * Set constituent name
     * 
     * @param string $first First name
     * @param string $last Last name
     */
    public function setName(string $first, string $last): void {
        $this->personalData['first_name'] = sanitize_text_field($first);
        $this->personalData['last_name'] = sanitize_text_field($last);
        
        if (!empty($first) && !empty($last)) {
            $this->personalData['full_name'] = trim($first . ' ' . $last);
        }
        
        $this->debug('Name set', $this->personalData);
    }
    
    /**
     * Set constituent email
     * 
     * @param string $email Email address
     */
    public function setEmail(string $email): void {
        $sanitized_email = sanitize_email($email);
        
        if (is_email($sanitized_email)) {
            $this->emailData[] = [
                'email_address' => $sanitized_email,
                'email_type' => 'Primary',
                'is_primary' => true
            ];
            
            $this->debug('Email set', $sanitized_email);
        } else {
            $this->debug('Invalid email address provided', $email);
        }
    }
    
    /**
     * Set constituent phone
     * 
     * @param string $phone Phone number
     */
    public function setPhone(string $phone): void {
        $sanitized_phone = $this->sanitizePhone($phone);
        
        if (!empty($sanitized_phone)) {
            $this->phoneData[] = [
                'phone_number' => $sanitized_phone,
                'phone_type' => 'Primary',
                'is_primary' => true
            ];
            
            $this->debug('Phone set', $sanitized_phone);
        }
    }
    
    /**
     * Set constituent address from user ID
     * 
     * @param int $user_id WordPress user ID
     */
    public function setAddress(int $user_id): void {
        if (!$user_id) {
            return;
        }
        
        $address_data = [
            'street_address' => get_user_meta($user_id, 'user-address-1', true),
            'street_address_2' => get_user_meta($user_id, 'user-address-2', true),
            'city' => get_user_meta($user_id, 'user-city', true),
            'state' => get_user_meta($user_id, 'user-state', true),
            'postal_code' => get_user_meta($user_id, 'user-postal-code', true),
            'country' => get_user_meta($user_id, 'user-country-of-origin', true) ?: 'US'
        ];
        
        // Only add address if we have at least street and city
        if (!empty($address_data['street_address']) && !empty($address_data['city'])) {
            $this->addressData[] = array_merge($address_data, [
                'address_type' => 'Primary',
                'is_primary' => true
            ]);
            
            $this->debug('Address set', $address_data);
        }
    }
    
    /**
     * Find setting key in list
     * 
     * @param string $item_name_to_find Item to find
     * @param string $list_column_name Column name to search
     * @param array $list List to search in
     * @return string|null Found key or null
     */
    public function findSettingKey(string $item_name_to_find, string $list_column_name, array $list): ?string {
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
     * Set membership data
     * 
     * @param int $user_id WordPress user ID
     * @param string|null $note Optional note
     * @param string|null $method Payment method
     * @param int|null $parent_uid Parent user ID for family memberships
     */
    public function setMembership(int $user_id, ?string $note = null, ?string $method = null, ?int $parent_uid = null): void {
        $this->debug('🎯 setMembership() CALLED!', ['user_id' => $user_id]);
        
        if (!$user_id) {
            $this->debug('❌ setMembership: Invalid user_id');
            return;
        }
        
        $membership_type = get_user_meta($user_id, 'user-membership-type', true);
        $membership_level = get_user_meta($user_id, 'user-membership-level', true);
        $membership_start = get_user_meta($user_id, 'user-membership-start-date', true);
        $membership_renewal = get_user_meta($user_id, 'user-membership-renewal-date', true);
        $membership_status = get_user_meta($user_id, 'user-membership-status', true) ?: 'active';
        
        // If no membership type is set, use the one from the current request/session
        if (!$membership_type) {
            $membership_type = 'Individual Membership'; // Default fallback
        }
        
        $this->debug('🔍 Looking up membership level config', ['user_id' => $user_id, 'type' => $membership_type]);
        
        $this->debug('🔍 Looking up membership level config', [
            'membership_type' => $membership_type,
            'user_id' => $user_id
        ]);
        
        // Get membership level configuration from the membership type name
        $level_config = $this->lgl->getMembershipLevel($membership_type);
        
        // If no config found by name, try to find by price (WooCommerce integration)
        if (!$level_config || empty($level_config['lgl_membership_level_id'])) {
            $this->debug('⚠️ No level config found by name, trying price-based lookup');
            $level_config = $this->findMembershipLevelByPrice($user_id);
        }
        
        $this->debug('📋 Final level config', $level_config);
        
        // Ensure we have a valid start date (use today if not set)
        if (!$membership_start || empty($membership_start)) {
            $membership_start = time(); // Use current timestamp
        }
        
        // Ensure we have a valid renewal date (1 year from start if not set)
        if (!$membership_renewal || empty($membership_renewal)) {
            $start_timestamp = is_numeric($membership_start) ? $membership_start : strtotime($membership_start);
            $membership_renewal = strtotime('+1 year', $start_timestamp);
        }
        
        // Ensure we have a valid membership level ID
        $membership_level_id = $level_config['lgl_membership_level_id'] ?? null;
        $membership_level_name = $level_config['level_name'] ?? $level_config['name'] ?? $membership_type;
        
        if (!$membership_level_id) {
            $this->debug('❌ No membership_level_id found! This will cause LGL API to fail.');
        }
        
        $membership_data = [
            'membership_type' => $membership_type,
            'membership_level_id' => $membership_level_id,
            'membership_level_name' => $membership_level_name,
            'membership_status' => $membership_status,
            'date_start' => $this->formatDateForApi($membership_start),
            'finish_date' => $this->formatDateForApi($membership_renewal),
            'payment_method' => $method,
            'note' => $note ?: 'Membership created via Modern LGL API on ' . date('Y-m-d'),
            'parent_user_id' => $parent_uid
        ];
        
        // Add LGL constituent type if available
        if ($level_config && isset($level_config['lgl_constituent_type'])) {
            $membership_data['lgl_constituent_type'] = $level_config['lgl_constituent_type'];
        }
        
        // Debug the membership data being created
        $this->debug('Modern Membership Data Created', [
            'user_id' => $user_id,
            'membership_type' => $membership_type,
            'level_config' => $level_config,
            'membership_data' => $membership_data
        ]);
        
        $this->membershipData = $membership_data;
        
        $this->debug('Final Membership Data Set', $membership_data);
    }
    
    /**
     * Find membership level configuration by price (for WooCommerce integration)
     * 
     * @param int $user_id WordPress user ID
     * @return array|null Membership level configuration
     */
    private function findMembershipLevelByPrice(int $user_id): ?array {
        // Get the most recent order for this user to find the price
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['completed', 'processing', 'on-hold'],
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        if (empty($orders)) {
            $this->debug('⚠️ No recent orders found for user', $user_id);
            return null;
        }
        
        $order = $orders[0];
        $order_total = (float) $order->get_total();
        
        $this->debug('🛒 Found recent order', [
            'order_id' => $order->get_id(),
            'total' => $order_total
        ]);
        
        // Get membership levels from settings
        $membership_levels = $this->lgl->getMembershipLevels();
        
        $this->debug('🔍 Constituents: Retrieved membership levels from ApiSettings', [
            'count' => count($membership_levels),
            'levels' => $membership_levels
        ]);
        
        if (empty($membership_levels)) {
            $this->debug('⚠️ No membership levels configured in settings');
            return null;
        }
        
        // Find matching level by price
        foreach ($membership_levels as $level) {
            $level_price = (float) ($level['price'] ?? 0);
            $price_difference = abs($level_price - $order_total);
            
            // Allow for tax/fee differences up to 10% of the base price
            $tolerance = max(5.00, $level_price * 0.10); // At least $5 or 10% of price
            
            $this->debug('🔍 Checking price match', [
                'level_name' => $level['level_name'] ?? 'Unknown',
                'level_price' => $level_price,
                'order_total' => $order_total,
                'difference' => $price_difference,
                'tolerance' => $tolerance,
                'matches' => $price_difference <= $tolerance
            ]);
            
            if ($price_difference <= $tolerance) {
                $this->debug('✅ Found matching membership level by price', [
                    'level' => $level,
                    'order_total' => $order_total,
                    'level_price' => $level_price,
                    'difference' => $price_difference
                ]);
                return $level;
            }
        }
        
        $this->debug('❌ No membership level found matching price', [
            'order_total' => $order_total,
            'available_levels' => array_map(function($level) {
                return [
                    'name' => $level['level_name'] ?? 'Unknown',
                    'price' => $level['price'] ?? 0
                ];
            }, $membership_levels)
        ]);
        
        return null;
    }
    
    /**
     * Set all constituent data from user ID
     * 
     * @param int $user_id WordPress user ID
     * @param bool $skip_membership Skip membership data
     */
    public function setData(int $user_id, bool $skip_membership = false): void {
        if (!$user_id) {
            $this->debug('Invalid user ID provided');
            return;
        }
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            $this->debug('User not found for ID', $user_id);
            return;
        }
        
        try {
            // Reset data
            $this->resetAllData();
            
            // Set basic personal data
            $this->setName(
                get_user_meta($user_id, 'user_firstname', true) ?: $user->first_name,
                get_user_meta($user_id, 'user_lastname', true) ?: $user->last_name
            );
            
            // Set email
            $this->setEmail($user->user_email);
            
            // Set phone
            $phone = get_user_meta($user_id, 'user_phone', true);
            if ($phone) {
                $this->setPhone($phone);
            }
            
            // Set address
            $this->setAddress($user_id);
            
            // Set additional personal data
            $this->personalData = array_merge($this->personalData, [
                'company' => get_user_meta($user_id, 'user_company', true),
                'title' => get_user_meta($user_id, 'user_title', true),
                'date_of_birth' => $this->formatDateForApi(get_user_meta($user_id, 'user_date_of_birth', true)),
                'gender' => get_user_meta($user_id, 'user_gender', true),
                'languages' => get_user_meta($user_id, 'user-languages', true),
                'interests' => get_user_meta($user_id, 'user_interests', true),
                'wordpress_user_id' => $user_id,
                'username' => $user->user_login
            ]);
            
            // Set membership data if not skipping
            if (!$skip_membership) {
                $this->setMembership($user_id);
            }
            
            // Set custom fields
            $this->setCustomFields($user_id);
            
            $this->debug('All data set for user', $user_id);
            
        } catch (\Exception $e) {
            $this->debug('Error setting data for user', ['user_id' => $user_id, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Set custom fields from user meta
     * 
     * @param int $user_id WordPress user ID
     */
    private function setCustomFields(int $user_id): void {
        $custom_field_mappings = [
            'subscription_id' => 'user-subscription-id',
            'subscription_status' => 'user-subscription-status',
            'lgl_id' => 'lgl_constituent_id',
            'source' => 'user_registration_source',
            'referral_source' => 'user_referral_source'
        ];
        
        foreach ($custom_field_mappings as $lgl_field => $wp_meta_key) {
            $value = get_user_meta($user_id, $wp_meta_key, true);
            if (!empty($value)) {
                $this->customData[$lgl_field] = $value;
            }
        }
    }
    
    /**
     * Set data and update constituent
     * 
     * @param int $user_id WordPress user ID
     * @param array $request Additional request data
     * @return array Update result
     */
    public function setDataAndUpdate(int $user_id, array $request = []): array {
        try {
            // Set all constituent data
            $this->setData($user_id);
            
            // Merge with additional request data
            if (!empty($request)) {
                $this->mergeRequestData($request);
            }
            
            // Get or create constituent
            $lgl_id = $this->getUserLglId($user_id);
            
            if ($lgl_id) {
                // Update existing constituent
                $result = $this->updateConstituent($lgl_id);
            } else {
                // Create new constituent
                $result = $this->createConstituent();
                
                // Save LGL ID to user meta if creation was successful
                if ($result['success'] && isset($result['data']['id'])) {
                    update_user_meta($user_id, 'lgl_constituent_id', $result['data']['id']);
                }
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug('Error in setDataAndUpdate', $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Merge additional request data
     * 
     * @param array $request Request data to merge
     */
    private function mergeRequestData(array $request): void {
        // Merge personal data
        if (isset($request['personal'])) {
            $this->personalData = array_merge($this->personalData, $request['personal']);
        }
        
        // Merge membership data
        if (isset($request['membership'])) {
            $this->membershipData = array_merge($this->membershipData, $request['membership']);
        }
        
        // Merge custom data
        if (isset($request['custom'])) {
            $this->customData = array_merge($this->customData, $request['custom']);
        }
    }
    
    /**
     * Create new constituent
     * 
     * @return array Creation result
     */
    public function createConstituent(): array {
        try {
            $connection = Connection::getInstance();
            
            $constituent_data = $this->buildConstituentData();
            
            $result = $connection->createConstituent($constituent_data);
            
            if ($result['success']) {
                $this->debug('Constituent created successfully', $result);
                
                // Cache the new constituent
                if (isset($result['data']['id'])) {
                    CacheManager::cacheConstituent($result['data']['id'], $result['data']);
                }
            } else {
                $this->debug('Failed to create constituent', $result['error'] ?? 'Unknown error');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug('Exception creating constituent', $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update existing constituent
     * 
     * @param string $lgl_id LGL constituent ID
     * @return array Update result
     */
    public function updateConstituent(string $lgl_id): array {
        try {
            $connection = Connection::getInstance();
            
            $constituent_data = $this->buildConstituentData();
            
            $result = $connection->updateConstituent($lgl_id, $constituent_data);
            
            if ($result['success']) {
                $this->debug('Constituent updated successfully', $result);
                
                // Update cache
                CacheManager::invalidateConstituent($lgl_id);
                if (isset($result['data'])) {
                    CacheManager::cacheConstituent($lgl_id, $result['data']);
                }
            } else {
                $this->debug('Failed to update constituent', ['lgl_id' => $lgl_id, 'error' => $result['error'] ?? 'Unknown error']);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug('Exception updating constituent', $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Build constituent data for API
     * 
     * @return array Formatted constituent data
     */
    private function buildConstituentData(): array {
        $data = [];
        
        // Add personal data
        if (!empty($this->personalData)) {
            $data = array_merge($data, $this->personalData);
        }
        
        // Add contact information
        if (!empty($this->emailData)) {
            $data['email_addresses'] = $this->emailData;
        }
        
        if (!empty($this->phoneData)) {
            $data['phone_numbers'] = $this->phoneData;
        }
        
        if (!empty($this->addressData)) {
            $data['addresses'] = $this->addressData;
        }
        
        // Add membership data
        if (!empty($this->membershipData)) {
            $data['membership'] = $this->membershipData;
        }
        
        // Add custom fields
        if (!empty($this->customData)) {
            $data['custom_fields'] = $this->customData;
        }
        
        // Add categories and groups
        if (!empty($this->categoryData)) {
            $data['categories'] = $this->categoryData;
        }
        
        if (!empty($this->groupsData)) {
            $data['groups'] = $this->groupsData;
        }
        
        return $data;
    }
    
    /**
     * Get user's LGL ID
     * 
     * @param int $user_id WordPress user ID
     * @return string|null LGL ID or null
     */
    private function getUserLglId(int $user_id): ?string {
        $lgl_meta_keys = ['lgl_constituent_id', 'lgl_id', 'lgl_user_id'];
        
        foreach ($lgl_meta_keys as $meta_key) {
            $lgl_id = get_user_meta($user_id, $meta_key, true);
            if (!empty($lgl_id)) {
                return $lgl_id;
            }
        }
        
        return null;
    }
    
    /**
     * Sanitize phone number
     * 
     * @param string $phone Phone number
     * @return string Sanitized phone
     */
    private function sanitizePhone(string $phone): string {
        // Remove all non-numeric characters except + and -
        $sanitized = preg_replace('/[^0-9+\-\s\(\)]/', '', $phone);
        
        // Ensure it's not empty and has reasonable length
        if (empty($sanitized) || strlen(preg_replace('/[^0-9]/', '', $sanitized)) < 10) {
            return '';
        }
        
        return trim($sanitized);
    }
    
    /**
     * Format date for API
     * 
     * @param string|int $date Date string or timestamp
     * @return string|null Formatted date or null
     */
    private function formatDateForApi($date): ?string {
        if (empty($date)) {
            return null;
        }
        
        // Handle Unix timestamp
        if (is_numeric($date)) {
            return date('Y-m-d', $date);
        }
        
        // Handle date string
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }
        
        return date('Y-m-d', $timestamp);
    }
    
    /**
     * Debug output with conditional display
     * 
     * @param string $message Debug message
     * @param mixed $data Optional data to display
     */
    private function debug(string $message, $data = null): void {
        $helper = Helper::getInstance();
        $helper->debug('LGL Constituents: ' . $message, $data);
    }
    
    /**
     * Get all constituent data
     * 
     * @return array All constituent data
     */
    public function getAllData(): array {
        return [
            'personal' => $this->personalData,
            'email' => $this->emailData,
            'phone' => $this->phoneData,
            'address' => $this->addressData,
            'membership' => $this->membershipData,
            'custom' => $this->customData,
            'categories' => $this->categoryData,
            'groups' => $this->groupsData
        ];
    }
}

// Maintain backward compatibility
if (!function_exists('lgl_constituents')) {
    function lgl_constituents(): Constituents {
        return Constituents::getInstance();
    }
}
