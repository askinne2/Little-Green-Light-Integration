<?php
/**
 * Membership Update Action
 * 
 * @deprecated 2.0.0 This action is deprecated. Use WooCommerce checkout for membership changes.
 *                   Membership type changes should be handled through WooCommerce orders, which
 *                   automatically deactivate old memberships and add new ones via MembershipRegistrationService.
 *                   Kept for backward compatibility with legacy JetFormBuilder forms only.
 * 
 * Handles membership updates through JetFormBuilder forms.
 * Updates existing memberships in LGL CRM and manages role assignments.
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
 * MembershipUpdateAction Class
 * 
 * @deprecated 2.0.0 Use WooCommerce checkout for membership type changes instead.
 *                   This class is kept for backward compatibility only.
 * 
 * Handles membership updates and role management
 */
class MembershipUpdateAction implements JetFormActionInterface {
    
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
     * Handle membership update action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('MembershipUpdateAction: Invalid request data', $request);
                $error_message = 'Invalid request data. Please check that all required fields are filled correctly.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        $this->helper->debug('MembershipUpdateAction: Processing request', $request);
        
        $uid = (int) $request['user_id'];
        $username = str_replace(' ', '%20', $request['username']);
        $membership_level = $request['ui-membership-type'];
        $order_id = $request['inserted_post_id'] ?? null;
        $price = $request['price'] ?? 0;
        $date = date('Y-m-d', time());
        
        if ($uid === 0) {
            $this->helper->debug('No User ID in Request, MembershipUpdateAction');
                $error_message = 'User ID is missing. Please ensure you are logged in and try again.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        // Get user email
        $user_data = get_userdata($uid);
        if (!$user_data) {
            $this->helper->debug('MembershipUpdateAction: User not found', $uid);
                $error_message = 'User account not found. Please try again or contact support.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        $email = $user_data->data->user_email;
        
        // Update user meta
        $this->updateUserMeta($uid, $membership_level);
        
        // Handle role changes
        $this->updateUserRole($uid, $membership_level);
        
        // Fix membership radio (legacy helper function)
        $this->helper->fixMembershipRadio($uid, $membership_level);
        
        // Process LGL membership update
        $this->processLGLMembershipUpdate($uid, $membership_level, $order_id, $price, $date, $request);
            
        } catch (\Exception $e) {
            $this->helper->debug('MembershipUpdateAction: Error occurred', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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
     * Update user meta data
     * 
     * @param int $uid User ID
     * @param string $membership_level Membership level
     * @return void
     */
    private function updateUserMeta(int $uid, string $membership_level): void {
        $meta = get_user_meta($uid);
        $meta['user-membership-type'][0] = $membership_level;
        update_user_meta($uid, 'user-membership-type', $membership_level);
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
     * Process LGL membership update
     * 
     * @param int $uid User ID
     * @param string $membership_level Membership level
     * @param mixed $order_id Order ID
     * @param float $price Price
     * @param string $date Date
     * @param array $request Full request data
     * @return void
     */
    private function processLGLMembershipUpdate(
        int $uid,
        string $membership_level,
        $order_id,
        float $price,
        string $date,
        array $request
    ): void {
        $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
        $existing_user = $this->connection->getConstituentData($user_lgl_id);
        
        if ($existing_user) {
            $this->updateExistingLGLUser($existing_user, $uid, $order_id, $price, $date, $request);
        } else {
            $this->createNewLGLUser($uid, $order_id, $price, $date, $request);
        }
    }
    
    /**
     * Update existing LGL user
     * 
     * @param mixed $existing_user Existing LGL user data
     * @param int $uid User ID
     * @param mixed $order_id Order ID
     * @param float $price Price
     * @param string $date Date
     * @param array $request Request data
     * @return void
     */
    private function updateExistingLGLUser(
        $existing_user,
        int $uid,
        $order_id,
        float $price,
        string $date,
        array $request
    ): void {
        $lgl_id = is_array($existing_user) ? ($existing_user['id'] ?? null) : ($existing_user->id ?? null);
        $this->helper->debug('LGL USER EXISTS: ', $lgl_id);
        
        if (!$lgl_id) {
                $error_message = 'Unable to update membership. LGL user ID not found. Please try again or contact support.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        // Get detailed user data
        $user = $this->connection->getConstituentData($lgl_id);
        if (!$user) {
            $this->helper->debug('MembershipUpdateAction: no user found', $lgl_id);
                $error_message = 'Unable to update membership. User data not found. Please try again or contact support.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        // Deactivate existing memberships
        $this->deactivateExistingMemberships($user, $lgl_id);
        
        // Add new membership
        $this->addNewMembership($lgl_id, $uid);
        
        // Add payment
        $this->addMembershipPayment($lgl_id, $order_id, $price, $date, $request);
    }
    
    /**
     * Create new LGL user
     * 
     * @param int $uid User ID
     * @param mixed $order_id Order ID
     * @param float $price Price
     * @param string $date Date
     * @param array $request Request data
     * @return void
     */
    private function createNewLGLUser(
        int $uid,
        $order_id,
        float $price,
        string $date,
        array $request
    ): void {
        $this->helper->debug('**** WP USER exists, but not in LGL *****');
        
        $lgl_id = $this->createMembershipConstituent($uid, $request);
        if (!$lgl_id) {
            $this->helper->debug('MembershipUpdateAction: Failed to create LGL constituent');
                $error_message = 'Failed to create membership account. Please try again or contact support.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        // Add new membership
        $this->addNewMembership($lgl_id, $uid);
        
        // Add payment
        $this->addMembershipPayment($lgl_id, $order_id, $price, $date, $request);
    }
    
    /**
     * Deactivate existing memberships
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
        
        $this->helper->debug('Retrieving MEMBERSHIP', $mid);
        
        // IMPORTANT: finish_date must be >= date_start per LGL API validation
        $today = date('Y-m-d');
        $todayTimestamp = strtotime($today);
        
        if (strtotime($lgl_membership->finish_date) >= $todayTimestamp) {
            $updated_membership = [
                'id' => $lgl_membership->id,
                'membership_level_id' => $lgl_membership->membership_level_id,
                'membership_level_name' => $lgl_membership->membership_level_name,
                'date_start' => $lgl_membership->date_start,
                'finish_date' => $today,  // Must be >= date_start
                'note' => 'Membership updated via WP_LGL_API on ' . date('Y-m-d')
            ];
            
            // Use modern Connection::updateMembership() - uses direct membership endpoint
            $result = $this->connection->updateMembership((string)$mid, $updated_membership);
            
            if (!$result) {
                $this->helper->debug('MembershipUpdateAction: couldn\'t update membership: ', $updated_membership);
            }
        }
    }
    
    /**
     * Add new membership
     * 
     * @param mixed $lgl_id LGL ID
     * @param int $uid User ID
     * @return void
     */
    private function addNewMembership($lgl_id, int $uid): void {
        $this->constituents->setMembership($uid);
        $membership_data = $this->constituents->getMembershipData();
        $this->helper->debug('Membership_Data:', $membership_data);
        
        $result = $this->connection->addMembership($lgl_id, $membership_data);
        
        if (!empty($result['success'])) {
            $this->helper->debug('Membership added successfully', $result);
        } else {
            $this->helper->debug('Failed to add membership', $result);
        }
    }
    
    /**
     * Add membership payment
     * 
     * @param mixed $lgl_id LGL ID
     * @param mixed $order_id Order ID
     * @param float $price Price
     * @param string $date Date
     * @param array $request Request data
     * @return void
     */
    private function addMembershipPayment($lgl_id, $order_id, float $price, string $date, array $request): void {
        if (array_key_exists('payment_type', $request)) {
            $payment_data = $this->payments->setupMembershipPayment($this, $order_id, $price, $date, $request['payment_type']);
        } else {
            $payment_data = $this->payments->setupMembershipPayment($this, $order_id, $price, $date);
        }
        
        if ($payment_data && isset($payment_data['success']) && $payment_data['success']) {
            // Payment already added by setupMembershipPayment() - just log the result
            $payment_id = $payment_data['id'] ?? null;
            
            if ($payment_id) {
                $this->helper->debug('Payment ID: ', $payment_id);
            } else {
                $this->helper->debug('MembershipUpdateAction: Payment created but no ID returned', $payment_data);
            }
        } else {
            $error = $payment_data['error'] ?? 'Unknown error';
            $this->helper->debug('MembershipUpdateAction: Failed to create payment', $error);
        }
    }
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string {
        return 'lgl_update_membership';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Update existing membership in LGL CRM system with role management';
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
                $this->helper->debug("MembershipUpdateAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('MembershipUpdateAction: Invalid user_id');
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
            'username',
            'ui-membership-type'
        ];
    }
    
    /**
     * Create a constituent for membership update scenarios
     * 
     * @param int $uid WordPress user ID
     * @param array $request Request data
     * @return int|false LGL constituent ID on success, false on failure
     */
    private function createMembershipConstituent(int $uid, array $request) {
        $user_info = get_userdata($uid);
        if (!$user_info) {
            return false;
        }
        
        // Extract user data
        $first_name = get_user_meta($uid, 'first_name', true) ?: $user_info->first_name;
        $last_name = get_user_meta($uid, 'last_name', true) ?: $user_info->last_name;
        $email = get_user_meta($uid, 'user_email', true) ?: $user_info->user_email;
        
        $constituent_data = [
            'external_constituent_id' => $uid,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'constituent_contact_type_id' => 1247,
            'constituent_contact_type_name' => 'Primary',
            'addressee' => $first_name . ' ' . $last_name,
            'salutation' => $first_name,
            'annual_report_name' => $first_name . ' ' . $last_name,
            'date_added' => date('Y-m-d')
        ];
        
        try {
            $response = $this->connection->createConstituent($constituent_data);
            $lgl_id = $response['data']['id'] ?? false;
            
            if ($lgl_id) {
                // Store the LGL ID
                update_user_meta($uid, 'lgl_id', $lgl_id);
            }
            
            return $lgl_id;
        } catch (\Exception $e) {
            $this->helper->debug('MembershipUpdateAction: Exception creating constituent', [
                'error' => $e->getMessage()
            ]);
            // Re-throw to be caught by handle() method
            throw $e;
        }
    }
}
