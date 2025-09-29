<?php
/**
 * Test Requests Class
 * 
 * Provides test data and mock requests for LGL API testing and development.
 * Contains sample registration, membership, and family member data.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Core;

/**
 * Test Requests Class
 * 
 * Manages test data for API development and testing
 */
class TestRequests {
    
    /**
     * Class instance
     * 
     * @var TestRequests|null
     */
    private static $instance = null;
    
    /**
     * Registration test data
     * 
     * @var array
     */
    private $registrationRequest = [];
    
    /**
     * Class registration test data
     * 
     * @var array
     */
    private $classReg = [];
    
    /**
     * Membership update test data
     * 
     * @var array
     */
    private $updateMembership = [];
    
    /**
     * Family member addition test data
     * 
     * @var array
     */
    private $addFamilyMemberRequest = [];
    
    /**
     * Get instance
     * 
     * @return TestRequests
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
        $this->initializeTestData();
    }
    
    /**
     * Initialize all test data
     */
    private function initializeTestData(): void {
        $this->makeRegistration();
        $this->makeClassRegistration();
        $this->makeUpdateMembership();
        $this->makeAddFamilyMember();
    }
    
    /**
     * Create registration test data
     */
    public function makeRegistration(): array {
        $this->registrationRequest = [
            'payment_type' => 'credit-card',
            'ui-membership-prices' => '100',
            'price_display_only' => '100.00',
            'user_firstname' => 'Andrew',
            'user_lastname' => 'Skinner',
            'user_company' => '21 ads media',
            'username' => 'test_lgl',
            'user_email' => 'test_lgl@example.com',
            '_password' => 'test_password_123',
            '_confirm_password' => 'test_password_123',
            'user_phone' => '314-234-1324',
            'user-address-1' => '57 Blake Street',
            'user-address-2' => '',
            'user-city' => 'Greenville',
            'user-state' => 'South Carolina',
            'user-postal-code' => '29605',
            'user-country-of-origin' => 'US',
            'user-languages' => ['English'],
            'user-membership-level' => 'individual',
            'user-membership-type' => 'Individual',
            'user-membership-start-date' => current_time('Y-m-d'),
            'user-membership-renewal-date' => date('Y-m-d', strtotime('+1 year')),
            'user-membership-status' => 'active',
            'user-subscription-id' => 'test_sub_' . uniqid(),
            'user-subscription-status' => 'active'
        ];
        
        return $this->registrationRequest;
    }
    
    /**
     * Create class registration test data
     */
    public function makeClassRegistration(): array {
        $this->classReg = [
            'user_id' => 1,
            'class_name' => 'Spanish Conversation',
            'class_level' => 'Beginner',
            'class_date' => date('Y-m-d', strtotime('+1 week')),
            'class_time' => '18:00',
            'class_duration' => '90',
            'class_instructor' => 'Maria Garcia',
            'class_location' => 'Upstate International Center',
            'class_cost' => '75.00',
            'payment_method' => 'credit-card',
            'registration_date' => current_time('Y-m-d H:i:s')
        ];
        
        return $this->classReg;
    }
    
    /**
     * Create membership update test data
     */
    public function makeUpdateMembership(): array {
        $this->updateMembership = [
            'user_id' => 1,
            'membership_level' => 'family',
            'membership_type' => 'Family',
            'membership_start_date' => current_time('Y-m-d'),
            'membership_renewal_date' => date('Y-m-d', strtotime('+1 year')),
            'membership_status' => 'active',
            'payment_amount' => '150.00',
            'payment_method' => 'credit-card',
            'update_reason' => 'upgrade',
            'update_date' => current_time('Y-m-d H:i:s')
        ];
        
        return $this->updateMembership;
    }
    
    /**
     * Create family member addition test data
     */
    public function makeAddFamilyMember(): array {
        $this->addFamilyMemberRequest = [
            'primary_user_id' => 1,
            'member_firstname' => 'Jane',
            'member_lastname' => 'Skinner',
            'member_email' => 'jane.skinner@example.com',
            'member_phone' => '314-234-5678',
            'member_date_of_birth' => '1985-03-15',
            'member_relationship' => 'spouse',
            'member_languages' => ['English', 'Spanish'],
            'member_interests' => ['Cultural Events', 'Language Classes'],
            'add_date' => current_time('Y-m-d H:i:s'),
            'status' => 'active'
        ];
        
        return $this->addFamilyMemberRequest;
    }
    
    /**
     * Get registration test data
     */
    public function getRegistrationRequest(): array {
        return $this->registrationRequest;
    }
    
    /**
     * Get class registration test data
     */
    public function getClassReg(): array {
        return $this->classReg;
    }
    
    /**
     * Get membership update test data
     */
    public function getUpdateMembership(): array {
        return $this->updateMembership;
    }
    
    /**
     * Get family member addition test data
     */
    public function getAddFamilyMemberRequest(): array {
        return $this->addFamilyMemberRequest;
    }
    
    /**
     * Get all test data
     */
    public function getAllTestData(): array {
        return [
            'registration' => $this->registrationRequest,
            'class_registration' => $this->classReg,
            'membership_update' => $this->updateMembership,
            'family_member' => $this->addFamilyMemberRequest
        ];
    }
    
    /**
     * Generate random test user data
     */
    public function generateRandomUserData(): array {
        $firstNames = ['John', 'Jane', 'Michael', 'Sarah', 'David', 'Lisa', 'Robert', 'Emily'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis'];
        $companies = ['Tech Corp', 'Global Solutions', 'Creative Agency', 'Business Partners', 'Innovation Labs'];
        $cities = ['Greenville', 'Charlotte', 'Asheville', 'Columbia', 'Charleston'];
        $states = ['South Carolina', 'North Carolina', 'Georgia', 'Tennessee'];
        $languages = ['English', 'Spanish', 'French', 'German', 'Italian', 'Portuguese'];
        
        $firstName = $firstNames[array_rand($firstNames)];
        $lastName = $lastNames[array_rand($lastNames)];
        
        return [
            'user_firstname' => $firstName,
            'user_lastname' => $lastName,
            'user_email' => strtolower($firstName . '.' . $lastName . '@example.com'),
            'user_company' => $companies[array_rand($companies)],
            'user_phone' => '(' . rand(200, 999) . ') ' . rand(200, 999) . '-' . rand(1000, 9999),
            'user-address-1' => rand(100, 9999) . ' ' . ['Main St', 'Oak Ave', 'Pine Rd', 'Elm Dr'][array_rand(['Main St', 'Oak Ave', 'Pine Rd', 'Elm Dr'])],
            'user-city' => $cities[array_rand($cities)],
            'user-state' => $states[array_rand($states)],
            'user-postal-code' => rand(10000, 99999),
            'user-country-of-origin' => 'US',
            'user-languages' => [
                $languages[array_rand($languages)],
                $languages[array_rand($languages)]
            ],
            'user-membership-level' => ['individual', 'family', 'student'][array_rand(['individual', 'family', 'student'])],
            'generated_at' => current_time('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Validate test data structure
     */
    public function validateTestData(array $data, string $type): array {
        $errors = [];
        
        switch ($type) {
            case 'registration':
                $required = ['user_firstname', 'user_lastname', 'user_email'];
                break;
            case 'class_registration':
                $required = ['user_id', 'class_name', 'class_date'];
                break;
            case 'membership_update':
                $required = ['user_id', 'membership_level', 'membership_status'];
                break;
            case 'family_member':
                $required = ['primary_user_id', 'member_firstname', 'member_lastname'];
                break;
            default:
                $required = [];
        }
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        return $errors;
    }
    
    /**
     * Reset all test data to defaults
     */
    public function resetTestData(): void {
        $this->initializeTestData();
    }
}

// Maintain backward compatibility
if (!function_exists('test_requests')) {
    function test_requests(): TestRequests {
        return TestRequests::getInstance();
    }
}
