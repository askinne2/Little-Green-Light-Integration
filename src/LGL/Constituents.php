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
use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;

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
            // Normalize phone for comparison (remove all non-digits to match Connection::normalizePhoneNumber)
            $normalized_for_comparison = preg_replace('/\D/', '', $sanitized_phone);
            
            // Check if this phone is already added (prevent duplicates)
            foreach ($this->phoneData as $existing_phone) {
                $existing_normalized = preg_replace('/\D/', '', $existing_phone['number'] ?? '');
                if ($existing_normalized === $normalized_for_comparison) {
                    $this->debug('Phone already set, skipping duplicate', $sanitized_phone);
                    return;
                }
            }
            
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
        
        $this->debug('🔍 setAddress: Checking user meta', [
            'user_id' => $user_id,
            'street1' => $street1,
            'city' => $city,
            'has_street1' => !empty($street1),
            'has_city' => !empty($city)
        ]);
        
        // Only add address if we have at least street and city
        if (!empty($street1) && !empty($city)) {
            $street = trim($street1 . ' ' . $street2);
            $addressEntry = [
                'street' => $street,
                'street_address_type_id' => 1,
                'street_type_name' => 'Home',
                'city' => $city,
                'state' => $state,
                'postal_code' => $postal_code,
                'county' => '',
                'country' => $country,
                'seasonal_from' => '01-01',
                'seasonal_to' => '12-31',
                'seasonal' => false,
                'is_preferred' => true,
                'not_current' => false
            ];
            $this->addressData[] = $addressEntry;
            
            $this->debug('✅ Address set and added to array', [
                'street' => $street,
                'city' => $city,
                'address_data_count' => count($this->addressData),
                'address_entry' => $addressEntry
            ]);
        } else {
            $this->debug('⚠️ setAddress: Skipping - missing street1 or city', [
                'street1' => $street1,
                'city' => $city
            ]);
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
        
        // CRITICAL FIX: Prioritize lgl_membership_level_id over user-membership-type
        // This ensures we use the correct membership level from the most recent order
        // rather than stale user-membership-type meta
        $membership_level_id_meta = (int) get_user_meta($user_id, 'lgl_membership_level_id', true);
        if ($membership_level_id_meta > 0) {
            $level_config_from_id = $this->lgl->getMembershipLevelByLglId($membership_level_id_meta);
            if ($level_config_from_id && !empty($level_config_from_id['level_name'])) {
                // Use the level name from the config, not stale user-membership-type
                $membership_type = $level_config_from_id['level_name'];
                $this->debug('✅ Using membership level from lgl_membership_level_id', [
                    'membership_level_id' => $membership_level_id_meta,
                    'membership_type' => $membership_type
                ]);
            }
        }
        
        // If no membership type is set, use the one from the current request/session
        if (!$membership_type) {
            $membership_type = 'Member'; // Updated default fallback (not "Individual Membership")
        }
        
        $this->debug('🔍 Looking up membership level config', [
            'membership_type' => $membership_type,
            'user_id' => $user_id,
            'lgl_membership_level_id' => $membership_level_id_meta
        ]);

        // Get membership level configuration - prioritize ID lookup (already done above)
        $level_config = null;
        if ($membership_level_id_meta > 0) {
            $level_config = $this->lgl->getMembershipLevelByLglId($membership_level_id_meta);
            $this->debug('🔍 Membership level config from lgl_membership_level_id', [
                'membership_level_id' => $membership_level_id_meta,
                'found' => $level_config ? 'YES' : 'NO',
                'level_name' => $level_config['level_name'] ?? 'N/A'
            ]);
        }

        // Fallback to name-based lookup if ID lookup failed
        if (!$level_config) {
            $level_config = $this->lgl->getMembershipLevel($membership_type);
            $this->debug('🔍 Membership level config from name lookup', [
                'membership_type' => $membership_type,
                'found' => $level_config ? 'YES' : 'NO'
            ]);
        }
        
        // Note: Price-based lookup removed - rely on _ui_lgl_sync_id on products instead
        // If no config found, log warning but continue (will fail gracefully at API call)
        if (!$level_config || empty($level_config['lgl_membership_level_id'])) {
            $this->debug('⚠️ No level config found by ID or name', [
                'membership_type' => $membership_type,
                'membership_level_id_meta' => $membership_level_id_meta,
                'note' => 'Ensure product has _ui_lgl_sync_id set or membership_type matches configured level slug'
            ]);
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
     * @param bool $skip_membership Skip membership sync (for non-membership orders)
     * @return array Update result
     */
    public function setDataAndUpdate(int $user_id, array $request = [], bool $skip_membership = false): array {
        try {
            // Set all constituent data (skip membership updates for non-membership orders)
            $this->setData($user_id, $skip_membership);
            
            // Merge with additional request data
            if (!empty($request)) {
                $this->mergeRequestData($request);
            }
            
            // Get or create constituent
            $lgl_id = $this->getUserLglId($user_id);
            
            if ($lgl_id) {
                // Update existing constituent (includes contact info in payload using legacy pattern)
                $result = $this->updateConstituent($lgl_id);
            } else {
                // Create new constituent
                $result = $this->createConstituent();
                
                // Save LGL ID to user meta if creation was successful (canonical field: lgl_id)
                if ($result['success'] && isset($result['data']['id'])) {
                    $lgl_id = $result['data']['id'];
                    update_user_meta($user_id, 'lgl_id', $lgl_id);
                    
                    // Add contact info for new constituent
                    $this->syncContactInfo($lgl_id);
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
     * Sync contact info (email, phone, address) with LGL
     * Updates existing or adds new contact info
     * 
     * @param string $lgl_id LGL constituent ID
     * @return void
     */
    private function syncContactInfo(string $lgl_id): void {
        $connection = Connection::getInstance();
        $helper = Helper::getInstance();
        
        // MAX 1 EMAIL, 1 PHONE, 1 ADDRESS - Delete all old ones first, then add new
        
        // Delete all existing emails first
        $existing_emails = $connection->getConstituentEmailAddresses($lgl_id);
        foreach ($existing_emails as $existing_email) {
            $email_id = is_array($existing_email) ? ($existing_email['id'] ?? null) : ($existing_email->id ?? null);
            if ($email_id) {
                $connection->deleteEmailAddress($lgl_id, $email_id);
                $helper->debug('🗑️ syncContactInfo: Deleted old email', [
                    'lgl_id' => $lgl_id,
                    'email_id' => $email_id
                ]);
            }
        }
        
        // Delete all existing phones first
        $existing_phones = $connection->getConstituentPhones($lgl_id);
        foreach ($existing_phones as $existing_phone) {
            $phone_id = is_array($existing_phone) ? ($existing_phone['id'] ?? null) : ($existing_phone->id ?? null);
            if ($phone_id) {
                $connection->deletePhoneNumber($lgl_id, $phone_id);
                $helper->debug('🗑️ syncContactInfo: Deleted old phone', [
                    'lgl_id' => $lgl_id,
                    'phone_id' => $phone_id
                ]);
            }
        }
        
        // Delete all existing addresses first
        $existing_addresses = $connection->getConstituentAddresses($lgl_id);
        foreach ($existing_addresses as $existing_address) {
            $address_id = is_array($existing_address) ? ($existing_address['id'] ?? null) : ($existing_address->id ?? null);
            if ($address_id) {
                $connection->deleteStreetAddress($lgl_id, $address_id);
                $helper->debug('🗑️ syncContactInfo: Deleted old address', [
                    'lgl_id' => $lgl_id,
                    'address_id' => $address_id
                ]);
            }
        }
        
        // Now add the new ones (max 1 of each)
        
        // Add email (only first one if multiple provided)
        $emailData = $this->getEmailData();
        if (!empty($emailData)) {
            $email = $emailData[0]; // Only use first email
            $email_address = strtolower(trim($email['address'] ?? ''));
            if (!empty($email_address)) {
                $response = $connection->addEmailAddress($lgl_id, $email);
                if ($response['success'] ?? false) {
                    $helper->debug('➕ syncContactInfo: Email added', [
                        'lgl_id' => $lgl_id,
                        'email' => $email_address
                    ]);
                } else {
                    $helper->debug('❌ syncContactInfo: Failed to add email', [
                        'lgl_id' => $lgl_id,
                        'error' => $response['error'] ?? 'Unknown error'
                    ]);
                }
            }
        }
        
        // Add phone (only first one if multiple provided)
        $phoneData = $this->getPhoneData();
        if (!empty($phoneData)) {
            $phone = $phoneData[0]; // Only use first phone
            $phone_number = preg_replace('/\D/', '', $phone['number'] ?? '');
            if (!empty($phone_number)) {
                $response = $connection->addPhoneNumber($lgl_id, $phone);
                if ($response['success'] ?? false) {
                    $helper->debug('➕ syncContactInfo: Phone added', [
                        'lgl_id' => $lgl_id,
                        'phone' => $phone['number'] ?? 'unknown'
                    ]);
                } else {
                    $helper->debug('❌ syncContactInfo: Failed to add phone', [
                        'lgl_id' => $lgl_id,
                        'error' => $response['error'] ?? 'Unknown error'
                    ]);
                }
            }
        }
        
        // Add address (only first one if multiple provided, and only if not empty)
        $addressData = $this->getAddressData();
        if (!empty($addressData)) {
            $address = $addressData[0]; // Only use first address
            $street = trim($address['street'] ?? '');
            if (!empty($street)) {
                $response = $connection->addStreetAddress($lgl_id, $address);
                if ($response['success'] ?? false) {
                    $helper->debug('➕ syncContactInfo: Address added', [
                        'lgl_id' => $lgl_id,
                        'street' => $street
                    ]);
                } else {
                    $helper->debug('❌ syncContactInfo: Failed to add address', [
                        'lgl_id' => $lgl_id,
                        'error' => $response['error'] ?? 'Unknown error'
                    ]);
                }
            } else {
                $helper->debug('⚠️ syncContactInfo: Skipping empty address', [
                    'lgl_id' => $lgl_id
                ]);
            }
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
            
            // Build update data WITHOUT contact info (emails/phones/addresses)
            // Contact info is synced separately using updateOrAdd methods to preserve existing records
            $constituent_data = $this->buildUpdateData();
            
            // Remove contact info from main update payload - we'll sync it separately
            unset($constituent_data['email_addresses']);
            unset($constituent_data['phone_numbers']);
            unset($constituent_data['street_addresses']);
            unset($constituent_data['remove_previous_email_addresses']);
            unset($constituent_data['remove_previous_phone_numbers']);
            unset($constituent_data['remove_previous_street_addresses']);
            unset($constituent_data['remove_previous_web_addresses']);
            
            $result = $connection->updateConstituent($lgl_id, $constituent_data);
            
            if ($result['success']) {
                $this->debug('Constituent updated successfully', $result);
                
                // Sync contact info separately (preserves existing records, updates/adds as needed)
                $this->syncContactInfo($lgl_id);
                
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
     * Build update data with contact info (legacy pattern)
     * Includes email, phone, and address in the update payload with remove_previous flags
     * 
     * @return array Formatted update data
     */
    private function buildUpdateData(): array {
        $helper = Helper::getInstance();
        
        // Start with personal data
        $update_data = $this->personalData;
        
        // Add remove_previous flags (legacy pattern)
        $flags = [
            'remove_previous_email_addresses' => true,
            'remove_previous_phone_numbers' => true,
            'remove_previous_street_addresses' => true,
            'remove_previous_web_addresses' => true,
        ];
        $update_data = array_merge($update_data, $flags);
        
        // Add email addresses if available
        if (!empty($this->emailData)) {
            $update_data['email_addresses'] = $this->emailData;
            $helper->debug('🔧 buildUpdateData: Including email addresses', [
                'count' => count($this->emailData),
                'emails' => array_map(function($e) { return $e['address'] ?? 'unknown'; }, $this->emailData)
            ]);
        }
        
        // Add phone numbers if available
        if (!empty($this->phoneData)) {
            $update_data['phone_numbers'] = $this->phoneData;
            $helper->debug('🔧 buildUpdateData: Including phone numbers', [
                'count' => count($this->phoneData),
                'phones' => array_map(function($p) { return $p['number'] ?? 'unknown'; }, $this->phoneData)
            ]);
        }
        
        // Add street addresses if available
        if (!empty($this->addressData)) {
            $update_data['street_addresses'] = $this->addressData;
            $helper->debug('🔧 buildUpdateData: Including street addresses', [
                'count' => count($this->addressData),
                'addresses' => array_map(function($a) { return $a['street'] ?? 'unknown'; }, $this->addressData)
            ]);
        } else {
            $helper->debug('⚠️ buildUpdateData: No address data available', [
                'address_data' => $this->addressData
            ]);
        }
        
        return $update_data;
    }
    
    /**
     * Get LGL ID from user meta
     * 
     * Checks canonical field first (lgl_id), then falls back to legacy fields for backward compatibility.
     * During migration, legacy fields (lgl_constituent_id, lgl_user_id) are checked but not preferred.
     * 
     * @param int $user_id WordPress user ID
     * @return string|null LGL constituent ID or null if not found
     */
    public function getUserLglId(int $user_id): ?string {
        // Canonical field: lgl_id (primary)
        $lgl_id = get_user_meta($user_id, 'lgl_id', true);
        if (!empty($lgl_id)) {
            return $lgl_id;
        }
        
        // Legacy fields: backward compatibility during migration
        // These will be migrated to lgl_id and eventually removed
        $legacy_meta_keys = ['lgl_constituent_id', 'lgl_user_id'];
        foreach ($legacy_meta_keys as $meta_key) {
            $lgl_id = get_user_meta($user_id, $meta_key, true);
            if (!empty($lgl_id)) {
                // Migrate legacy field to canonical field
                update_user_meta($user_id, 'lgl_id', $lgl_id);
                $this->debug('Migrated legacy meta field to canonical lgl_id', [
                    'user_id' => $user_id,
                    'legacy_field' => $meta_key,
                    'lgl_id' => $lgl_id
                ]);
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
        $this->debug('🔍 getAddressData: Returning address data', [
            'count' => count($this->addressData),
            'data' => $this->addressData
        ]);
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
