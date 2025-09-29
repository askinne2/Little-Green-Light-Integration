<?php
/**
 * Family Member Action
 * 
 * Handles family member addition through JetFormBuilder forms.
 * Adds family members to existing family memberships in LGL CRM.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder\Actions;

use UpstateInternational\LGL\LGL\Helper;

/**
 * FamilyMemberAction Class
 * 
 * Handles family member registration with parent membership inheritance
 */
class FamilyMemberAction implements JetFormActionInterface {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     */
    public function __construct(Helper $helper) {
        $this->helper = $helper;
    }
    
    /**
     * Handle family member addition action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('FamilyMemberAction: Invalid request data', $request);
            return;
        }
        
        $this->helper->debug('FamilyMemberAction: Processing request', $request);
        
        $child_uid = (int) $request['user_id'];
        $parent_uid = (int) $request['parent_user_id'];
        
        // Get parent membership information
        $parent_membership_info = $this->getParentMembershipInfo($parent_uid);
        
        // Get child user information
        $child_info = $this->getChildUserInfo($child_uid, $request);
        
        // Create child request with parent membership data
        $child_request = $this->buildChildRequest($child_uid, $child_info, $request, $parent_membership_info);
        
        $this->helper->debug('FamilyMemberAction: CHILD REQUEST', $child_request);
        
        // Update child user meta with parent's membership information
        $this->inheritParentMembership($child_uid, $parent_uid, $parent_membership_info);
        
        // Register child user via UserRegistrationAction
        $this->registerFamilyMember($child_request, $action_handler, $parent_uid);
    }
    
    /**
     * Get parent membership information
     * 
     * @param int $parent_uid Parent user ID
     * @return array Parent membership data
     */
    private function getParentMembershipInfo(int $parent_uid): array {
        return [
            'paypal_membership' => get_user_meta($parent_uid, 'user-membership-level-paypal', true),
            'membership_type' => get_user_meta($parent_uid, 'user-membership-type', true),
            'renewal_date' => get_user_meta($parent_uid, 'user-membership-renewal-date', true),
            'start_date' => get_user_meta($parent_uid, 'user-membership-start-date', true),
            'subscription_id' => get_user_meta($parent_uid, 'user-subscription-id', true),
            'subscription_status' => get_user_meta($parent_uid, 'user-subscription-status', true),
        ];
    }
    
    /**
     * Get child user information
     * 
     * @param int $child_uid Child user ID
     * @param array $request Request data
     * @return array Child user data
     */
    private function getChildUserInfo(int $child_uid, array $request): array {
        return [
            'firstname' => get_user_meta($child_uid, 'first_name', true),
            'lastname' => get_user_meta($child_uid, 'last_name', true),
            'email' => get_user_meta($child_uid, 'user_email', true),
        ];
    }
    
    /**
     * Build child registration request
     * 
     * @param int $child_uid Child user ID
     * @param array $child_info Child user information
     * @param array $request Original request data
     * @param array $parent_membership_info Parent membership information
     * @return array Child request data
     */
    private function buildChildRequest(
        int $child_uid,
        array $child_info,
        array $request,
        array $parent_membership_info
    ): array {
        return [
            'user_id' => $child_uid,
            'user_firstname' => $child_info['firstname'],
            'user_lastname' => $child_info['lastname'],
            'username' => $request['username'] ?? ($child_info['firstname'] . ' ' . $child_info['lastname']),
            'user_email' => $child_info['email'],
            'user_phone' => $request['user_phone'] ?? '',
            'user-address-1' => $request['user-address-1'] ?? '',
            'user-address-2' => $request['user-address-2'] ?? '',
            'user-city' => $request['user-city'] ?? '',
            'user-state' => $request['user-state'] ?? '',
            'user-postal-code' => $request['user-postal-code'] ?? '',
            'ui-membership-type' => $parent_membership_info['membership_type'],
            'parent_user_id' => $request['parent_user_id']
        ];
    }
    
    /**
     * Inherit parent membership data to child
     * 
     * @param int $child_uid Child user ID
     * @param int $parent_uid Parent user ID
     * @param array $parent_membership_info Parent membership information
     * @return void
     */
    private function inheritParentMembership(
        int $child_uid,
        int $parent_uid,
        array $parent_membership_info
    ): void {
        // Update child meta with parent's membership information
        update_user_meta($child_uid, 'user-membership-type', $parent_membership_info['membership_type']);
        update_user_meta($child_uid, 'user-membership-level-paypal', $parent_membership_info['paypal_membership']);
        update_user_meta($child_uid, 'user-membership-renewal-date', $parent_membership_info['renewal_date']);
        update_user_meta($child_uid, 'user-membership-start-date', $parent_membership_info['start_date']);
        update_user_meta($child_uid, 'user-subscription-id', $parent_membership_info['subscription_id']);
        update_user_meta($child_uid, 'user-subscription-status', $parent_membership_info['subscription_status']);
        
        $this->helper->debug('FamilyMemberAction: Child membership data inherited from parent', [
            'child_uid' => $child_uid,
            'parent_uid' => $parent_uid,
            'membership_type' => $parent_membership_info['membership_type']
        ]);
    }
    
    /**
     * Register family member via UserRegistrationAction
     * 
     * @param array $child_request Child request data
     * @param mixed $action_handler Action handler
     * @param int $parent_uid Parent user ID
     * @return void
     */
    private function registerFamilyMember(array $child_request, $action_handler, int $parent_uid): void {
        // For now, delegate to legacy method to maintain functionality
        // In a full modernization, this would use the UserRegistrationAction directly
        if (class_exists('LGL_API')) {
            $lgl_api = \LGL_API::get_instance();
            $lgl_api->lgl_register_user($child_request, null, $parent_uid);
        } else {
            $this->helper->debug('FamilyMemberAction: LGL_API class not found, cannot register family member');
        }
    }
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string {
        return 'lgl_add_family_member';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Add family member to existing family membership in LGL CRM';
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
                $this->helper->debug("FamilyMemberAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('FamilyMemberAction: Invalid user_id');
            return false;
        }
        
        // Validate parent_user_id is numeric and positive
        if (!isset($request['parent_user_id']) || !is_numeric($request['parent_user_id']) || (int)$request['parent_user_id'] <= 0) {
            $this->helper->debug('FamilyMemberAction: Invalid parent_user_id');
            return false;
        }
        
        // Ensure child and parent are different users
        if ((int)$request['user_id'] === (int)$request['parent_user_id']) {
            $this->helper->debug('FamilyMemberAction: Child and parent user IDs cannot be the same');
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
            'parent_user_id'
        ];
    }
}
