<?php
/**
 * Membership Renewal Action
 * 
 * Handles membership renewals through JetFormBuilder forms.
 * Renews existing memberships in LGL CRM and manages renewal dates.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder\Actions;

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Constituents;
use UpstateInternational\LGL\LGL\Payments;

/**
 * MembershipRenewalAction Class
 * 
 * Handles membership renewals and date management
 */
class MembershipRenewalAction implements JetFormActionInterface {
    
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
     * LGL Constituents service
     * 
     * @var Constituents
     */
    private Constituents $constituents;
    
    /**
     * LGL Payments service
     * 
     * @var Payments
     */
    private Payments $payments;
    
    /**
     * Constructor
     * 
     * @param Connection $connection LGL connection service
     * @param Helper $helper LGL helper service
     * @param Constituents $constituents LGL constituents service
     * @param Payments $payments LGL payments service
     */
    public function __construct(
        Connection $connection,
        Helper $helper,
        Constituents $constituents,
        Payments $payments
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->constituents = $constituents;
        $this->payments = $payments;
    }
    
    /**
     * Handle membership renewal action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('MembershipRenewalAction: Invalid request data', $request);
            return;
        }
        
        $post_id = $request['inserted_post_id'] ?? null;
        if (!$post_id) {
            $this->helper->debug('MembershipRenewalAction: No post_id provided');
            return;
        }
        
        $this->helper->debug('MembershipRenewalAction: Processing renewal', $request);
        
        $uid = (int) $request['user_id'];
        $username = $request['user_name'] ?? '';
        $email = $request['user_email'] ?? '';
        $date = date('Y-m-d', time());
        $membership_level = $request['user_membership_level_new'] ?? '';
        $price = $request['price'] ?? 0;
        $order_id = $request['inserted_post_id'];
        
        if ($uid === 0) {
            $this->helper->debug('No User ID in Request, MembershipRenewalAction');
            return;
        }
        
        // Update renewal date
        $this->updateRenewalDate($uid);
        
        // Handle role changes
        $this->updateUserRole($uid, $membership_level);
        
        // Fix membership radio (legacy helper function)
        $this->helper->fixMembershipRadio($uid, $membership_level);
        
        // Process LGL membership renewal
        $this->processLGLMembershipRenewal($uid, $email, $order_id, $price, $date);
    }
    
    /**
     * Update renewal date
     * 
     * @param int $uid User ID
     * @return void
     */
    private function updateRenewalDate(int $uid): void {
        $current_start_date = get_user_meta($uid, 'user-membership-start-date', true);
        $new_date = strtotime('+1 year', $current_start_date);
        
        $this->helper->debug('New renewal date:', date('Y-m-d', $new_date));
        update_user_meta($uid, 'user-membership-renewal-date', $new_date);
    }
    
    /**
     * Update user role based on membership level
     * 
     * @param int $uid User ID
     * @param string $membership_level Membership level
     * @return void
     */
    private function updateUserRole(int $uid, string $membership_level): void {
        if (in_array($membership_level, ['Family Membership', 'Patron Family Membership'])) {
            $this->helper->debug('PRIVILEGE ESCALATION --> FAMILY');
            $this->helper->changeUserRole($uid, 'ui_member', 'ui_patron_owner');
        } elseif (in_array($membership_level, ['Individual Membership', 'Patron Membership'])) {
            $this->helper->debug('PRIVILEGE RESTRICTION --> INDIVIDUAL');
            $this->helper->changeUserRole($uid, 'ui_patron_owner', 'ui_member');
        }
    }
    
    /**
     * Process LGL membership renewal
     * 
     * @param int $uid User ID
     * @param string $email User email
     * @param mixed $order_id Order ID
     * @param float $price Price
     * @param string $date Date
     * @return void
     */
    private function processLGLMembershipRenewal(
        int $uid,
        string $email,
        $order_id,
        float $price,
        string $date
    ): void {
        $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
        $existing_user = $this->connection->getLglData('SINGLE_CONSTITUENT', $user_lgl_id);
        
        if (!$existing_user) {
            $this->helper->debug('MembershipRenewalAction: No existing LGL user found');
            return;
        }
        
        $lgl_id = $existing_user->id;
        $this->helper->debug('LGL USER EXISTS: ', $lgl_id);
        
        if (!$lgl_id) {
            return;
        }
        
        // Get detailed user data
        $user = $this->connection->getLglData('SINGLE_CONSTITUENT', $lgl_id);
        if (!$user) {
            $this->helper->debug('MembershipRenewalAction: no user found by ID', $email);
            return;
        }
        
        // Deactivate existing memberships
        $this->deactivateExistingMemberships($user, $lgl_id);
        
        // Add new membership
        $this->addRenewalMembership($lgl_id, $uid);
        
        // Add payment
        $this->addRenewalPayment($lgl_id, $order_id, $price, $date);
    }
    
    /**
     * Deactivate existing memberships for renewal
     * 
     * @param mixed $user LGL user data
     * @param mixed $lgl_id LGL ID
     * @return void
     */
    private function deactivateExistingMemberships($user, $lgl_id): void {
        $memberships = $user->memberships ?? [];
        
        if (empty($memberships)) {
            return;
        }
        
        // Mark most recent membership inactive as of yesterday
        $lgl_membership = $memberships[0];
        $mid = $lgl_membership->id;
        
        $this->helper->debug('Retrieving MEMBERSHIP for renewal', $mid);
        
        $yesterday = (new \DateTime())->format('Y-m-d');
        $today = strtotime(date('Y-m-d'));
        
        if (strtotime($lgl_membership->finish_date) >= $today) {
            $updated_membership = [
                'id' => $lgl_membership->id,
                'membership_level_id' => $lgl_membership->membership_level_id,
                'membership_level_name' => $lgl_membership->membership_level_name,
                'date_start' => $lgl_membership->date_start,
                'finish_date' => $yesterday,
                'note' => 'Membership DEACTIVATED via WP_LGL_API on ' . date('Y-m-d')
            ];
            
            $result = $this->connection->updateLglObject($lgl_id, $updated_membership, null, 'MEMBERSHIPS', $mid);
            
            if (!$result) {
                $this->helper->debug('MembershipRenewalAction: couldn\'t update membership: ', $updated_membership);
            }
        }
    }
    
    /**
     * Add renewal membership
     * 
     * @param mixed $lgl_id LGL ID
     * @param int $uid User ID
     * @return void
     */
    private function addRenewalMembership($lgl_id, int $uid): void {
        $this->constituents->setMembership($uid);
        $this->helper->debug('Renewal Membership_Data:', $this->constituents->getMembershipData());
        
        $this->connection->addLglObject($lgl_id, $this->constituents->getMembershipData(), 'memberships.json');
    }
    
    /**
     * Add renewal payment
     * 
     * @param mixed $lgl_id LGL ID
     * @param mixed $order_id Order ID
     * @param float $price Price
     * @param string $date Date
     * @return void
     */
    private function addRenewalPayment($lgl_id, $order_id, float $price, string $date): void {
        $payment_data = $this->payments->setupMembershipPayment($this, $order_id, $price, $date);
        
        if ($payment_data) {
            $payment_id = $this->connection->addLglObject($lgl_id, $payment_data, 'gifts.json');
            
            if ($payment_id) {
                $this->helper->debug('Renewal Payment ID: ', $payment_id);
            }
        }
    }
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string {
        return 'lgl_renew_membership';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Renew existing membership in LGL CRM system';
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
                $this->helper->debug("MembershipRenewalAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('MembershipRenewalAction: Invalid user_id');
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
            'inserted_post_id'
        ];
    }
}
