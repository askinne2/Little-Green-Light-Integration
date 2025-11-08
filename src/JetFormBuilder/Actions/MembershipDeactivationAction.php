<?php
/**
 * Membership Deactivation Action
 * 
 * Handles membership deactivation through JetFormBuilder forms.
 * Deactivates existing memberships in LGL CRM and manages user status.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder\Actions;

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;

/**
 * MembershipDeactivationAction Class
 * 
 * Handles membership deactivation and user status updates
 */
class MembershipDeactivationAction implements JetFormActionInterface {
    
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
     * LGL WP Users service
     * 
     * @var WpUsers
     */
    private WpUsers $wpUsers;
    
    /**
     * Constructor
     * 
     * @param Connection $connection LGL connection service
     * @param Helper $helper LGL helper service
     * @param WpUsers $wpUsers LGL WP Users service
     */
    public function __construct(
        Connection $connection,
        Helper $helper,
        WpUsers $wpUsers
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
    }
    
    /**
     * Handle membership deactivation action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('MembershipDeactivationAction: Invalid request data', $request);
            return;
        }
        
        $this->helper->debug('MembershipDeactivationAction: Processing request', $request);
        
        $uid = (int) $request['user_id'];
        
        if ($uid === 0) {
            $this->helper->debug('No User ID in Request, MembershipDeactivationAction');
            return;
        }
        
        // Process membership deactivation in LGL
        $this->deactivateLGLMembership($uid);
        
        // Process WordPress user deactivation
        $this->deactivateWordPressUser($uid);
    }
    
    /**
     * Deactivate membership in LGL CRM
     * 
     * @param int $uid WordPress user ID
     * @return void
     */
    private function deactivateLGLMembership(int $uid): void {
        // Get user's LGL ID
        $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
        
        if (!$user_lgl_id) {
            $this->helper->debug('MembershipDeactivationAction: No LGL ID found for user', $uid);
            return;
        }
        
        // Get existing user data from LGL
        $existing_user = $this->connection->getLglData('SINGLE_CONSTITUENT', $user_lgl_id);
        
        if (!$existing_user) {
            $this->helper->debug('MembershipDeactivationAction: No existing LGL user found');
            return;
        }
        
        $lgl_id = $existing_user->id;
        $this->helper->debug('LGL USER EXISTS: ', $lgl_id);
        
        if (!$lgl_id) {
            $this->helper->debug('MembershipDeactivationAction: Invalid LGL ID');
            return;
        }
        
        // Get detailed user data with memberships
        $user = $this->connection->getLglData('SINGLE_CONSTITUENT', $lgl_id);
        
        if (!$user) {
            $this->helper->debug('MembershipDeactivationAction: Could not retrieve user details');
            return;
        }
        
        // Process membership deactivation
        $this->deactivateUserMemberships($user, $lgl_id);
    }
    
    /**
     * Deactivate user memberships in LGL
     * 
     * @param mixed $user LGL user data
     * @param mixed $lgl_id LGL user ID
     * @return void
     */
    private function deactivateUserMemberships($user, $lgl_id): void {
        $memberships = $user->memberships ?? [];
        
        if (empty($memberships)) {
            $this->helper->debug('MembershipDeactivationAction: No memberships found for user');
            return;
        }
        
        // Mark most recent membership inactive as of yesterday
        $lgl_membership = $memberships[0];
        $mid = $lgl_membership->id;
        
        $this->helper->debug('Retrieving MEMBERSHIP for deactivation', $mid);
        
        // IMPORTANT: finish_date must be >= date_start per LGL API validation
        $today = date('Y-m-d');
        $todayTimestamp = strtotime($today);
        
        // Only deactivate if membership is currently active
        if (strtotime($lgl_membership->finish_date) >= $todayTimestamp) {
            $updated_membership = [
                'id' => $lgl_membership->id,
                'membership_level_id' => $lgl_membership->membership_level_id,
                'membership_level_name' => $lgl_membership->membership_level_name,
                'date_start' => $lgl_membership->date_start,
                'finish_date' => $today,  // Must be >= date_start
                'note' => 'Membership DEACTIVATED via WP_LGL_API on ' . date('Y-m-d')
            ];
            
            // Use modern Connection::updateMembership() - uses direct membership endpoint
            $result = $this->connection->updateMembership((string)$mid, $updated_membership);
            
            if ($result) {
                $this->helper->debug('Successfully deactivated membership', $mid);
            } else {
                $this->helper->debug('MembershipDeactivationAction: couldn\'t update membership: ', $updated_membership);
            }
        } else {
            $this->helper->debug('MembershipDeactivationAction: Membership already expired or inactive');
        }
    }
    
    /**
     * Deactivate WordPress user
     * 
     * @param int $uid WordPress user ID
     * @return void
     */
    private function deactivateWordPressUser(int $uid): void {
        try {
            $this->wpUsers->uiUserDeactivation($uid);
            $this->helper->debug('WordPress user deactivated successfully', $uid);
        } catch (\Exception $e) {
            $this->helper->debug('MembershipDeactivationAction: Error deactivating WordPress user', [
                'uid' => $uid,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string {
        return 'lgl_deactivate_membership';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Deactivate user membership in LGL CRM and WordPress';
    }
    
    /**
     * Get action priority
     * 
     * @return int
     */
    public function getPriority(): int {
        return 13; // Higher priority as specified in original code
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
                $this->helper->debug("MembershipDeactivationAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('MembershipDeactivationAction: Invalid user_id');
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
            'user_id'
        ];
    }
}
