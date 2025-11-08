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
        
        // Note: LGL does NOT accept 'full_name' field - it auto-generates from first_name/last_name
        // Removed: $this->personalData['full_name']
        
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
            // Check if this email is already added (prevent duplicates)
            foreach ($this->emailData as $existing_email) {
                if ($existing_email['address'] === $sanitized_email) {
                    $this->debug('Email already set, skipping duplicate', $sanitized_email);
                    return;
                }
            }
            
            $this->emailData[] = [
                'address' => $sanitized_email,
                'email_address_type_id' => 1,
                'email_type_name' => 'Home',
                'is_preferred' => true,
                'not_current' => false
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
                'number' => $sanitized_phone,
                'phone_number_type_id' => 1,
                'phone_type_name' => 'Home',
                'is_preferred' => true,
                'not_current' => false
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
        
        $street1 = get_user_meta($user_id, 'user-address-1', true);
        $street2 = get_user_meta($user_id, 'user-address-2', true);
        $city = get_user_meta($user_id, 'user-city', true);
        $state = get_user_meta($user_id, 'user-state', true);
        $postal_code = get_user_meta($user_id, 'user-postal-code', true);
        $country = get_user_meta($user_id, 'user-country-of-origin', true) ?: 'US';
        
        // Only add address if we have at least street and city
        if (!empty($street1) && !empty($city)) {
            $street = trim($street1 . ' ' . $street2);
            $this->addressData[] = [
                'street' => $street,
                'street_address_type_id' => 1,
                'street_type_name' => 'Home',
                'city' => $city,
                'state' => $state,
                'postal_code' => $postal_code,
                'country' => $country,
                'is_preferred' => true,
                'not_current' => false
            ];
            
            $this->debug('Address set', ['street' => $street, 'city' => $city]);
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
        
        $this->debug('🔍 Looking up membership level config', [
            'membership_type' => $membership_type,
            'user_id' => $user_id
        ]);

        $membership_level_id_meta = (int) get_user_meta($user_id, 'lgl_membership_level_id', true);

        // Get membership level configuration from the membership type name
        $level_config = null;
        if ($membership_level_id_meta > 0) {
            $level_config = $this->lgl->getMembershipLevelByLglId($membership_level_id_meta);
            $this->debug('🔍 Membership level config from user meta', [
                'membership_level_id' => $membership_level_id_meta,
                'found' => $level_config ? 'YES' : 'NO'
            ]);
        }

        if (!$level_config) {
            $level_config = $this->lgl->getMembershipLevel($membership_type);
        }
        
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
        
        // Build membership data matching legacy structure (membership_level_id, membership_level_name, date_start, finish_date, note)
        $membership_data = [
            'membership_level_id' => $membership_level_id,
            'membership_level_name' => $membership_level_name,
            'date_start' => $this->formatDateForApi($membership_start),
            'finish_date' => $this->formatDateForApi($membership_renewal),
            'note' => $note ?: 'Membership created via Modern LGL API on ' . date('Y-m-d')
        ];
        
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
        if (!function_exists('wc_get_orders')) {
            return null;
        }
        
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
            
            // Set additional personal data (matching legacy lgl-constituents.php structure)
            $first_name = $this->personalData['first_name'] ?? '';
            $last_name = $this->personalData['last_name'] ?? '';
            $full_name = trim($first_name . ' ' . $last_name);
            
            // MINIMAL personal data for initial constituent creation (matching legacy pattern)
            // Legacy sends ONLY personal_data first, then adds email/phone/address/membership separately
            $this->personalData = array_merge($this->personalData, [
                'is_org' => false,
                'constituent_contact_type_id' => 1247,
                'constituent_contact_type_name' => 'Primary',
                'addressee' => $full_name,
                'salutation' => $first_name,
                'sort_name' => $last_name . ', ' . $first_name,
                'is_deceased' => false,
                'annual_report_name' => $full_name,
                'date_added' => date('Y-m-d'),
                'is_anon' => false
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
            'wordpress_user_id' => $user_id, // Store WP user ID
            'subscription_id' => get_user_meta($user_id, 'user-subscription-id', true),
            'subscription_status' => get_user_meta($user_id, 'user-subscription-status', true),
            'source' => get_user_meta($user_id, 'user_registration_source', true),
            'referral_source' => get_user_meta($user_id, 'user_referral_source', true)
            // NOTE: DO NOT include lgl_id here - it's set by LGL when creating the constituent
        ];
        
        foreach ($custom_field_mappings as $lgl_field => $value) {
            if (!empty($value) && $lgl_field !== 'wordpress_user_id') {
                $this->customData[$lgl_field] = $value;
            } elseif ($lgl_field === 'wordpress_user_id') {
                $this->customData[$lgl_field] = (string) $value; // Always include WP user ID
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
        // MULTI-REQUEST PATTERN: Only return minimal personal data for initial constituent creation
        // Email, phone, address, and membership are added via separate POST requests AFTER creation
        // This is the ONLY pattern that works reliably with LGL's API
        
        return $this->personalData;
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
    
    /**
     * Get email data for multi-request pattern
     * 
     * @return array Email data
     */
    public function getEmailData(): array {
        return $this->emailData;
    }
    
    /**
     * Get phone data for multi-request pattern
     * 
     * @return array Phone data
     */
    public function getPhoneData(): array {
        return $this->phoneData;
    }
    
    /**
     * Get address data for multi-request pattern
     * 
     * @return array Address data
     */
    public function getAddressData(): array {
        return $this->addressData;
    }
    
    /**
     * Get membership data for multi-request pattern
     * 
     * @return array Membership data
     */
    public function getMembershipData(): array {
        return $this->membershipData;
    }
}

// Maintain backward compatibility
if (!function_exists('lgl_constituents')) {
    function lgl_constituents(): Constituents {
        return Constituents::getInstance();
    }
}
