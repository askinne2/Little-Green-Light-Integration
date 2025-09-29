<?php
/**
 * User Registration Action
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
     * Constructor
     * 
     * @param Connection $connection LGL connection service
     * @param Helper $helper LGL helper service
     */
    public function __construct(Connection $connection, Helper $helper) {
        $this->connection = $connection;
        $this->helper = $helper;
    }
    
    /**
     * Handle user registration action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('UserRegistrationAction: Invalid request data', $request);
            return;
        }
        
        $this->helper->debug('UserRegistrationAction: Processing request', $request);
        
        $uid = (int) $request['user_id'];
        $username = $request['user_firstname'] . '%20' . $request['user_lastname'];
        $email = $request['user_email'];
        $membership_level = $request['ui-membership-type'];
        $method = $request['method'] ?? null; // For family member registration
        
        // Set payment method
        $this->setPaymentMethod($uid, $request);
        
        if ($method) {
            $this->helper->debug("ADD FAMILY USER, MEMBERSHIP: ", $membership_level);
        }
        
        if ($uid === 0) {
            $this->helper->debug('No User ID in Request, UserRegistrationAction');
            return;
        }
        
        // Handle family membership role assignment
        if (!$method) {
            $this->assignMembershipRole($uid, $membership_level);
        }
        
        // Search for existing LGL contact
        $lgl_id = $this->connection->searchByName($username, $email);
        
        if (!$lgl_id) {
            // Create new constituent
            $this->createNewConstituent($uid, $request, $action_handler, $method, $membership_level);
        } else {
            // Handle existing constituent
            $this->handleExistingConstituent($uid, $lgl_id, $request, $action_handler, $method);
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
        $this->helper->debug('Payment method set: ', get_user_meta($uid, 'payment-method', true));
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
     * Create new constituent in LGL
     * 
     * @param int $uid User ID
     * @param array $request Request data
     * @param mixed $action_handler Action handler
     * @param mixed $method Method flag
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
        if (!$method) {
            update_user_meta($uid, 'user-membership-type', $membership_level);
            $lgl_id = $this->connection->addConstituent($uid);
        } else {
            $lgl_id = $this->connection->addConstituent($uid, $request['parent_user_id']);
        }
        
        $this->helper->debug('Constituent LGL ID: ', $lgl_id);
        
        if ($this->connection->isNewConstituent()) {
            $this->helper->debug('**** Inside constituent flag, UserRegistrationAction');
            
            update_user_meta($uid, 'lgl_id', $lgl_id);
            update_user_meta($uid, 'user-membership-type', $membership_level);
            
            if (!$method) {
                $payment_type = $request['payment_type'] ?? 'online';
                $this->connection->addMembershipPayment($lgl_id, $request, $payment_type);
            }
            
            $this->connection->resetNewConstituentFlag();
        } else {
            $this->helper->debug('**** else constituent flag, UserRegistrationAction, will update_membership()');
            // Delegate to membership update action
            $this->updateMembership($request, $action_handler);
            $this->connection->resetNewConstituentFlag();
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
    private function handleExistingConstituent(
        int $uid,
        $lgl_id,
        array $request,
        $action_handler,
        $method
    ): void {
        $this->helper->debug('UserRegistrationAction: FOUND LGL USER: ', $lgl_id);
        
        update_user_meta($uid, 'lgl_id', $lgl_id);
        
        if ($method) {
            $this->updateExistingFamilyMember($uid, $lgl_id);
        } else {
            $this->updateMembership($request, $action_handler);
        }
    }
    
    /**
     * Update membership (delegates to appropriate service)
     * 
     * @param array $request Request data
     * @param mixed $action_handler Action handler
     * @return void
     */
    private function updateMembership(array $request, $action_handler): void {
        // This would typically delegate to a membership update service
        // For now, we'll maintain the legacy call structure
        if (class_exists('LGL_API')) {
            $lgl_api = \LGL_API::get_instance();
            $lgl_api->lgl_update_membership($request, $action_handler);
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
                $this->helper->debug("UserRegistrationAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] < 0) {
            $this->helper->debug('UserRegistrationAction: Invalid user_id');
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
}
