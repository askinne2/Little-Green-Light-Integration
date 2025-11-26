<?php
/**
 * User Registration Action
 * 
 * @deprecated 2.0.0 This action is deprecated. Use MembershipRegistrationService instead.
 *                   Kept for backward compatibility with legacy JetFormBuilder forms (e.g., Form ID: 68820).
 *                   New registrations should use WooCommerce checkout or MembershipRegistrationService directly.
 * 
 * Handles user registration through JetFormBuilder forms.
 * Registers new users in LGL CRM and manages membership assignments.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder\Actions;

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\JetFormBuilder\AsyncJetFormProcessor;

/**
 * UserRegistrationAction Class
 * 
 * Handles new user registration and LGL integration
 */
class UserRegistrationAction implements JetFormActionInterface {
    
    /**
     * LGL Connection service
     * 
     * @var Connection
     */
    private Connection $connection;
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Async processor for LGL API calls
     * 
     * @var AsyncJetFormProcessor|null
     */
    private ?AsyncJetFormProcessor $asyncProcessor = null;
    
    /**
     * Constructor
     * 
     * @param Connection $connection LGL connection service
     * @param Helper $helper LGL helper service
     * @param AsyncJetFormProcessor|null $asyncProcessor Async processor (optional)
     */
    public function __construct(
        Connection $connection,
        Helper $helper,
        ?AsyncJetFormProcessor $asyncProcessor = null
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->asyncProcessor = $asyncProcessor;
    }
    
    /**
     * Set async processor (for dependency injection)
     * 
     * @param AsyncJetFormProcessor $asyncProcessor Async processor
     * @return void
     */
    public function setAsyncProcessor(AsyncJetFormProcessor $asyncProcessor): void {
        $this->asyncProcessor = $asyncProcessor;
    }
    
    /**
     * Handle user registration action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
        $this->helper->info('LGL UserRegistrationAction: Processing user registration', [
            'user_id' => $request['user_id'] ?? 'N/A',
            'user_email' => $request['user_email'] ?? 'N/A'
        ]);
        
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->error('LGL UserRegistrationAction: Invalid request data', [
                'missing_fields' => $this->getMissingFields($request)
            ]);
                $error_message = 'Invalid request data. Please check that all required fields are filled correctly.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        $uid = (int) $request['user_id'];
        $username = $request['user_firstname'] . '%20' . $request['user_lastname'];
        $email = $request['user_email'];
        $membership_level = $request['ui-membership-type'];
        $method = $request['method'] ?? null; // For family member registration
        
            // Set payment method
            $this->setPaymentMethod($uid, $request);
            
            if ($uid === 0) {
                $this->helper->error('LGL UserRegistrationAction: No User ID in Request', [
                    'request_keys' => array_keys($request)
                ]);
                $error_message = 'User ID is missing. Please ensure you are logged in and try again.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
            }
            
            // Handle family membership role assignment (synchronous - WordPress operation)
            if (!$method) {
                $this->assignMembershipRole($uid, $membership_level);
            }
            
            // Use async processing if available (speeds up form submission)
            if ($this->asyncProcessor) {
                $this->helper->debug('LGL UserRegistrationAction: Scheduling async LGL processing', [
                    'user_id' => $uid
                ]);
                
                try {
                    $emails = $this->collectCandidateEmails($uid, $request);
                    $context = [
                        'request' => $request,
                        'username' => $username,
                        'emails' => $emails,
                        'skip_payment' => (bool) $method // Skip payment for family members
                    ];
                    
                    $this->asyncProcessor->scheduleAsyncProcessing('user_registration', $uid, $context);
                    
                    $this->helper->info('LGL UserRegistrationAction: User registration completed (async)', [
                        'user_id' => $uid,
                        'membership_level' => $membership_level
                    ]);
                } catch (\Exception $e) {
                    $this->helper->warning('LGL UserRegistrationAction: Async scheduling failed, falling back to sync', [
                        'error' => $e->getMessage(),
                        'user_id' => $uid
                    ]);
                    
                    // Fallback to synchronous processing if async fails
                    $this->processRegistrationSync($uid, $request, $username, $method);
                    $this->helper->info('LGL UserRegistrationAction: User registration completed (sync)', [
                        'user_id' => $uid,
                        'membership_level' => $membership_level
                    ]);
                }
            } else {
                // Fallback: synchronous processing if async processor not available
                $this->processRegistrationSync($uid, $request, $username, $method);
                $this->helper->info('LGL UserRegistrationAction: User registration completed (sync)', [
                    'user_id' => $uid,
                    'membership_level' => $membership_level
                ]);
            }
            
        } catch (\Exception $e) {
            $this->helper->error('LGL UserRegistrationAction: Registration failed', [
                'error' => $e->getMessage(),
                'user_id' => $uid ?? null
            ]);
            
            // Re-throw Action_Exception, convert others
            if ($e instanceof \Jet_Form_Builder\Exceptions\Action_Exception || 
                $e instanceof \JFB_Modules\Actions\V2\Action_Exception) {
                throw $e;
            }
            
            // Convert to Action_Exception if possible
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($e->getMessage());
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($e->getMessage());
            } else {
                throw $e;
            }
        }
    }
    
    /**
     * Set payment method based on request data
     * 
     * @param int $uid User ID
     * @param array $request Request data
     * @return void
     */
    private function setPaymentMethod(int $uid, array $request): void {
        $payment_method = 'online'; // Default
        
        if (array_key_exists('payment_type', $request)) {
            $payment_type = $request['payment_type'];
            $payment_method = ($payment_type === 'credit-card') ? 'online' : 'offline';
        }
        
        update_user_meta($uid, 'payment-method', $payment_method);
    }
    
    /**
     * Assign membership role based on membership level
     * 
     * @param int $uid User ID
     * @param string $membership_level Membership level
     * @return void
     */
    private function assignMembershipRole(int $uid, string $membership_level): void {
        if (in_array($membership_level, ['Family Membership', 'Patron Family Membership'])) {
            $this->helper->changeUserRole($uid, 'ui_member', 'ui_patron_owner');
        }
    }

    /**
     * Collect candidate email addresses for matching
     *
     * @param int $uid
     * @param array $request
     * @return array<string>
     */
    private function collectCandidateEmails(int $uid, array $request): array {
        $user = get_userdata($uid);
        $emails = [
            $request['user_email'] ?? null,
            $request['billing_email'] ?? null,
            $user ? $user->user_email : null
        ];

        $emails = array_values(array_unique(array_filter(array_map(function($email) {
            $email = is_string($email) ? trim($email) : '';
            return $email !== '' ? strtolower($email) : null;
        }, $emails))));

        return $emails;
    }
    
    /**
     * Create new constituent in LGL
     * 
     * @param int $uid User ID
     * @param array $request Request data
     * @param mixed $action_handler Action handler
     * @param mixed $method Method flag for family members
     * @param string $membership_level Membership level
     * @return void
     */
    private function createNewConstituent(
        int $uid,
        array $request,
        $action_handler,
        $method,
        string $membership_level
    ): void {
        // Set membership type before creating constituent
        if (!$method) {
            update_user_meta($uid, 'user-membership-type', $membership_level);
        }
        
        // Build constituent data from WordPress user
        $constituent_data = $this->buildConstituentData($uid, $request, $method, $membership_level);
        
        try {
            // Create constituent using Connection::createConstituent()
            $response = $this->connection->createConstituent($constituent_data);
            
            if (isset($response['data']['id'])) {
                $lgl_id = $response['data']['id'];
                $this->helper->info('LGL UserRegistrationAction: Created new constituent', [
                    'lgl_id' => $lgl_id,
                    'user_id' => $uid
                ]);
                
                // Store LGL ID and membership type
                update_user_meta($uid, 'lgl_id', $lgl_id);
                update_user_meta($uid, 'user-membership-type', $membership_level);
                
                // Add additional constituent data
                $this->addConstituentExtras($lgl_id, $uid, $request, $method);
                
                // Add payment for regular members (not family members)
                if (!$method) {
                    $payment_type = $request['payment_type'] ?? 'online';
                    $this->connection->addMembershipPayment($lgl_id, $request, $payment_type);
                }
                
                $this->connection->resetNewConstituentFlag();
                
            } else {
                $this->helper->error('LGL UserRegistrationAction: Failed to create constituent', [
                    'user_id' => $uid,
                    'error' => $response['error'] ?? 'Unknown error'
                ]);
                $error_message = 'Failed to create your account. Please try again or contact support if the problem persists.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
            }
            
        } catch (\Exception $e) {
            $this->helper->error('LGL UserRegistrationAction: Exception creating constituent', [
                'user_id' => $uid,
                'error' => $e->getMessage()
            ]);
            
            // Re-throw Action_Exception, convert others
            if ($e instanceof \Jet_Form_Builder\Exceptions\Action_Exception || 
                $e instanceof \JFB_Modules\Actions\V2\Action_Exception) {
                throw $e;
            }
            
            // Convert to Action_Exception if possible
            $error_message = 'Failed to create your account. Please try again or contact support.';
            
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
            } else {
                throw new \RuntimeException($error_message);
            }
        }
    }
    
    /**
     * Handle existing constituent
     * 
     * @param int $uid User ID
     * @param mixed $lgl_id LGL ID
     * @param array $request Request data
     * @param mixed $action_handler Action handler
     * @param mixed $method Method flag
     * @return void
     */
    /**
     * Process registration synchronously (fallback method)
     * 
     * @param int $uid WordPress user ID
     * @param array $request Form data
     * @param string $username Username for search
     * @param mixed $method Method flag for family members
     * @return void
     */
    private function processRegistrationSync(int $uid, array $request, string $username, $method): void {
        // Search for existing LGL contact
        $emails = $this->collectCandidateEmails($uid, $request);
        $match = $this->connection->searchByName($username, $emails);
        $lgl_id = $match['id'] ?? null;

        $this->helper->debug('LGL UserRegistrationAction: LGL search result', [
            'lgl_id' => $lgl_id,
            'found_existing' => $lgl_id ? true : false
        ]);

        // Follow Logic Model: Always add membership and payment objects regardless of user existence
        if (!$lgl_id) {
            // CREATE LGL USER [Constituents::createConstituent()]
            $lgl_id = $this->createConstituent($uid, $request);
            update_user_meta($uid, 'lgl_id', $lgl_id);
        } else {
            // UPDATE LGL USER [Constituents::updateConstituent()]
            $this->updateConstituent($uid, $lgl_id, $request);
        }
        
        // ADD LGL PAYMENT OBJECT [Payments::createGift()] - ONLY FOR PAID REGISTRATIONS
        // Skip payment object creation for family members (they don't pay, parent already paid)
        if (!$method) {
            $this->addPaymentObject($uid, $lgl_id, $request);
        }
    }
    
    /**
     * Create LGL constituent (Logic Model: Constituents::createConstituent())
     */
    private function createConstituent(int $uid, array $request): string {
        $constituents = \UpstateInternational\LGL\LGL\Constituents::getInstance();
        $constituents->setData($uid);
        $constituent_data = $constituents->createConstituent();

        $response = $this->connection->createConstituent($constituent_data);
        $httpCode = $response['http_code'] ?? 0;
        if (
            !is_array($response)
            || empty($response['success'])
            || $httpCode < 200
            || $httpCode >= 300
            || empty($response['data']['id'])
        ) {
            $error_message = $response['error'] ?? 'Failed to create your account. Please try again or contact support if the problem persists.';
            
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
            } else {
                throw new \RuntimeException($error_message);
            }
        }

        $lgl_id = (string) $response['data']['id'];
        $this->helper->info('LGL UserRegistrationAction: Created LGL constituent', [
            'lgl_id' => $lgl_id,
            'user_id' => $uid
        ]);

        return $lgl_id;
    }
    
    /**
     * Update LGL constituent (Logic Model: Constituents::updateConstituent())
     */
    private function updateConstituent(int $uid, string $lgl_id, array $request): void {
        $constituents = \UpstateInternational\LGL\LGL\Constituents::getInstance();
        $constituents->setData($uid);
        $constituent_data = $constituents->updateConstituent($lgl_id);
        
        $this->connection->updateConstituent($lgl_id, $constituent_data);
        $this->helper->info('LGL UserRegistrationAction: Updated LGL constituent', [
            'lgl_id' => $lgl_id,
            'user_id' => $uid
        ]);
    }
    
    /**
     * Add payment object (Logic Model: Payments::createGift())
     */
    private function addPaymentObject(int $uid, string $lgl_id, array $request): void {
        try {
            $payments = \UpstateInternational\LGL\LGL\Payments::getInstance();
            
            // Extract payment details from request
            $order_id = $request['order_id'] ?? $request['inserted_post_id'] ?? 0;
            $price = (float)($request['price'] ?? 0);
            $date = date('Y-m-d');
            $payment_type = $request['payment_method'] ?? $request['payment_type'] ?? 'online';
            
            // Validate required data
            if ($order_id <= 0) {
                throw new \Exception('Invalid order_id: ' . $order_id);
            }
            
            if ($price <= 0) {
                throw new \Exception('Invalid price: ' . $price);
            }
            
            // Create payment using Logic Model method (this already creates the payment in LGL)
            $payment_result = $payments->setupMembershipPayment($lgl_id, $order_id, $price, $date, $payment_type);
            
            // Check if payment creation was successful
            if (!$payment_result['success']) {
                throw new \Exception('Payment creation failed: ' . ($payment_result['error'] ?? 'Unknown error'));
            }
            
            $this->helper->info('LGL UserRegistrationAction: Added payment object', [
                'payment_id' => $payment_result['id'] ?? 'N/A',
                'lgl_id' => $lgl_id,
                'order_id' => $order_id,
                'amount' => $price
            ]);
            
        } catch (\Exception $e) {
            $this->helper->error('LGL UserRegistrationAction: Payment creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $uid,
                'lgl_id' => $lgl_id
            ]);
            // Don't throw - let the process continue even if payment fails
        }
    }
    
    /**
     * Update existing family member
     * 
     * @param int $uid User ID
     * @param mixed $lgl_id LGL ID
     * @return void
     */
    private function updateExistingFamilyMember(int $uid, $lgl_id): void {
        // This would typically delegate to a family member service
        // For now, we'll maintain the legacy call structure
        if (class_exists('LGL_API')) {
            $lgl_api = \LGL_API::get_instance();
            $lgl_api->lgl_update_existing_family_member($uid, $lgl_id);
        }
    }
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string {
        return 'lgl_register_user';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Register new user in LGL CRM system with membership assignment';
    }
    
    /**
     * Get action priority
     * 
     * @return int
     */
    public function getPriority(): int {
        return 10;
    }
    
    /**
     * Get number of accepted arguments
     * 
     * @return int
     */
    public function getAcceptedArgs(): int {
        return 2;
    }
    
    /**
     * Validate request data before processing
     * 
     * @param array $request Form data
     * @return bool
     */
    public function validateRequest(array $request): bool {
        $required_fields = $this->getRequiredFields();
        
        foreach ($required_fields as $field) {
            if (!isset($request[$field]) || empty($request[$field])) {
                $this->helper->debug("LGL UserRegistrationAction: Missing required field", ['field' => $field]);
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] < 0) {
            $this->helper->debug('LGL UserRegistrationAction: Invalid user_id', ['user_id' => $request['user_id'] ?? null]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get required fields for this action
     * 
     * @return array<string>
     */
    public function getRequiredFields(): array {
        return [
            'user_id',
            'user_firstname',
            'user_lastname',
            'user_email',
            'ui-membership-type'
        ];
    }
    
    /**
     * Get missing required fields from request
     * 
     * @param array $request Request data
     * @return array<string> Missing field names
     */
    private function getMissingFields(array $request): array {
        $required_fields = $this->getRequiredFields();
        $missing = [];
        
        foreach ($required_fields as $field) {
            if (!isset($request[$field]) || empty($request[$field])) {
                $missing[] = $field;
            }
        }
        
        return $missing;
    }
    
    /**
     * Build constituent data from WordPress user and request data
     * 
     * @param int $uid WordPress user ID
     * @param array $request Request data from form
     * @param mixed $method Family member method flag
     * @param string $membership_level Membership level
     * @return array Constituent data for LGL API
     */
    private function buildConstituentData(int $uid, array $request, $method, string $membership_level): array {
        // Get WordPress user data
        $user_info = get_userdata($uid);
        if (!$user_info) {
            $error_message = "User account not found. Please try registering again.";
            
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
            } else {
                throw new \RuntimeException($error_message);
            }
        }
        
        // Extract names from request or user data
        // Priority: WordPress user object fields > request data > meta fields
        $first_name = !empty($user_info->first_name) ? $user_info->first_name : ($request['user_firstname'] ?? get_user_meta($uid, 'first_name', true));
        $last_name = !empty($user_info->last_name) ? $user_info->last_name : ($request['user_lastname'] ?? get_user_meta($uid, 'last_name', true));
        $email = !empty($user_info->user_email) ? $user_info->user_email : ($request['user_email'] ?? get_user_meta($uid, 'user_email', true));
        
        // Build base constituent data
        $constituent_data = [
            'external_constituent_id' => $uid,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'constituent_contact_type_id' => 1247, // Primary contact type
            'constituent_contact_type_name' => 'Primary',
            'addressee' => $first_name . ' ' . $last_name,
            'salutation' => $first_name,
            'annual_report_name' => $first_name . ' ' . $last_name,
            'org_name' => get_user_meta($uid, 'user-company', true) ?: ($request['user_company'] ?? ''),
            'date_added' => date('Y-m-d')
        ];
        
        // Handle family member relationships
        if ($method && isset($request['parent_user_id'])) {
            $parent_lgl_id = get_user_meta($request['parent_user_id'], 'lgl_id', true);
        }
        
        return $constituent_data;
    }
    
    /**
     * Add additional data to constituent (email, phone, address, membership, relationships)
     * 
     * @param int $lgl_id LGL constituent ID
     * @param int $uid WordPress user ID
     * @param array $request Request data
     * @param mixed $method Family member method flag
     * @return void
     */
    private function addConstituentExtras(int $lgl_id, int $uid, array $request, $method): void {
        try {
            // Add email address
            $email = $request['user_email'] ?? get_user_meta($uid, 'user_email', true) ?: get_userdata($uid)->user_email;
            if ($email) {
                $this->addConstituentEmail($lgl_id, $email);
            }
            
            // Add phone number
            $phone = $request['user_phone'] ?? get_user_meta($uid, 'user-phone', true);
            if ($phone) {
                $this->addConstituentPhone($lgl_id, $phone);
            }
            
            // Add address
            $this->addConstituentAddress($lgl_id, $uid, $request);
            
            // Add membership or family relationship
            if (!$method) {
                // Regular member - add membership
                $this->addConstituentMembership($lgl_id, $uid, $request);
            } else {
                // Family member - add relationship
                if (isset($request['parent_user_id'])) {
                    $this->addFamilyMemberRelationship($lgl_id, $uid, $request['parent_user_id']);
                }
            }
            
        } catch (\Exception $e) {
            $this->helper->error('LGL UserRegistrationAction: Error adding constituent extras', [
                'lgl_id' => $lgl_id,
                'user_id' => $uid,
                'error' => $e->getMessage()
            ]);
            // Don't throw here - let the main handle() method catch and convert
            throw $e;
        }
    }
    
    /**
     * Add email address to constituent
     * 
     * @param int $lgl_id LGL constituent ID
     * @param string $email Email address
     * @return void
     */
    private function addConstituentEmail(int $lgl_id, string $email): void {
        $email_data = [
            'address' => $email,
            'email_address_type_id' => 1, // Primary email type
            'email_address_type_name' => 'Primary'
        ];
        
        $response = $this->connection->addEmailAddressSafe($lgl_id, $email_data);
        if (isset($response['skipped']) && $response['skipped']) {
            // Email already exists - no need to log
        } else {
            $this->helper->debug('LGL UserRegistrationAction: Added email address', ['email' => $email]);
        }
    }
    
    /**
     * Add phone number to constituent
     * 
     * @param int $lgl_id LGL constituent ID
     * @param string $phone Phone number
     * @return void
     */
    private function addConstituentPhone(int $lgl_id, string $phone): void {
        $phone_data = [
            'number' => $phone,
            'phone_number_type_id' => 1, // Primary phone type
            'phone_number_type_name' => 'Primary'
        ];
        
        $response = $this->connection->addPhoneNumberSafe($lgl_id, $phone_data);
        if (isset($response['skipped']) && $response['skipped']) {
            // Phone already exists - no need to log
        } else {
            $this->helper->debug('LGL UserRegistrationAction: Added phone number', ['phone' => $phone]);
        }
    }
    
    /**
     * Add address to constituent
     * 
     * @param int $lgl_id LGL constituent ID
     * @param int $uid WordPress user ID
     * @param array $request Request data
     * @return void
     */
    private function addConstituentAddress(int $lgl_id, int $uid, array $request): void {
        $address_data = [
            'street_address' => $request['user_address'] ?? get_user_meta($uid, 'user-address', true),
            'city' => $request['user_city'] ?? get_user_meta($uid, 'user-city', true),
            'state' => $request['user_state'] ?? get_user_meta($uid, 'user-state', true),
            'postal_code' => $request['user_zip'] ?? get_user_meta($uid, 'user-zip', true),
            'country' => $request['user_country'] ?? get_user_meta($uid, 'user-country', true) ?: 'US',
            'street_address_type_id' => 1, // Primary address type
            'street_address_type_name' => 'Primary'
        ];
        
        // Only add if we have some address data
        if ($address_data['street_address'] || $address_data['city']) {
            $response = $this->connection->makeRequest("constituents/{$lgl_id}/street_addresses.json", 'POST', $address_data, false);
            $this->helper->debug('LGL UserRegistrationAction: Added address', ['lgl_id' => $lgl_id]);
        }
    }
    
    /**
     * Add membership to constituent
     * 
     * @param int $lgl_id LGL constituent ID
     * @param int $uid WordPress user ID
     * @param array $request Request data
     * @return void
     */
    private function addConstituentMembership(int $lgl_id, int $uid, array $request): void {
        $membership_type = get_user_meta($uid, 'user-membership-type', true);
        
        if ($membership_type) {
            $membership_data = [
                'membership_level_name' => $membership_type,
                'start_date' => date('Y-m-d'),
                'notes' => 'Added via WordPress LGL Integration'
            ];
            
            $response = $this->connection->makeRequest("constituents/{$lgl_id}/memberships.json", 'POST', $membership_data, false);
            $this->helper->debug('LGL UserRegistrationAction: Added membership', [
                'membership_type' => $membership_type,
                'lgl_id' => $lgl_id
            ]);
        }
    }
    
    /**
     * Add family member relationship
     * 
     * @param int $child_lgl_id Child LGL constituent ID
     * @param int $child_uid Child WordPress user ID
     * @param int $parent_uid Parent WordPress user ID
     * @return void
     */
    private function addFamilyMemberRelationship(int $child_lgl_id, int $child_uid, int $parent_uid): void {
        $parent_lgl_id = get_user_meta($parent_uid, 'lgl_id', true);
        
        if ($parent_lgl_id) {
            $relationship_data = [
                'related_constituent_id' => $parent_lgl_id,
                'relationship_type_name' => 'Child',
                'notes' => 'Child Member of LGL User: ' . $parent_lgl_id
            ];
            
            $response = $this->connection->makeRequest("constituents/{$child_lgl_id}/relationships.json", 'POST', $relationship_data, false);
            $this->helper->debug('LGL UserRegistrationAction: Added family relationship', [
                'child_lgl_id' => $child_lgl_id,
                'parent_lgl_id' => $parent_lgl_id
            ]);
            
            // Set membership type for family member
            update_user_meta($child_uid, 'user-membership-type', 'CHILD');
        }
    }
}
