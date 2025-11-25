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
use UpstateInternational\LGL\JetFormBuilder\AsyncJetFormProcessor;

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
     * @param Constituents $constituents LGL constituents service
     * @param Payments $payments LGL payments service
     * @param AsyncJetFormProcessor|null $asyncProcessor Async processor (optional)
     */
    public function __construct(
        Connection $connection,
        Helper $helper,
        Constituents $constituents,
        Payments $payments,
        ?AsyncJetFormProcessor $asyncProcessor = null
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->constituents = $constituents;
        $this->payments = $payments;
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
     * Handle membership renewal action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
        $this->helper->info('LGL MembershipRenewalAction: Processing membership renewal', [
            'user_id' => $request['user_id'] ?? 'N/A',
            'order_id' => $request['inserted_post_id'] ?? 'N/A'
        ]);
        
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->error('LGL MembershipRenewalAction: Invalid request data', [
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
        
        $post_id = $request['inserted_post_id'] ?? null;
        if (!$post_id) {
            $this->helper->error('LGL MembershipRenewalAction: No post_id provided');
                $error_message = 'Order ID is missing. Please try again or contact support.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        $uid = (int) $request['user_id'];
        $username = $request['user_name'] ?? '';
        $email = $request['user_email'] ?? '';
        $date = date('Y-m-d', time());
        $membership_level = $request['user_membership_level_new'] ?? '';
        $price = $request['price'] ?? 0;
        $order_id = $request['inserted_post_id'];
        
        if ($uid === 0) {
            $this->helper->error('LGL MembershipRenewalAction: No User ID in Request', [
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
        
        // Update renewal date (synchronous - WordPress operation)
        $this->updateRenewalDate($uid);
        
        // Handle role changes (synchronous - WordPress operation)
        $this->updateUserRole($uid, $membership_level);
        
        // Fix membership radio (legacy helper function) (synchronous - WordPress operation)
        $this->helper->fixMembershipRadio($uid, $membership_level);
        
        // Use async processing if available (speeds up form submission)
        if ($this->asyncProcessor) {
            $this->helper->debug('LGL MembershipRenewalAction: Scheduling async LGL processing', [
                'user_id' => $uid
            ]);
            
            try {
                $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
                $context = [
                    'lgl_id' => $user_lgl_id,
                    'order_id' => $order_id,
                    'price' => $price,
                    'date' => $date
                ];
                
                $this->asyncProcessor->scheduleAsyncProcessing('membership_renewal', $uid, $context);
                
                $this->helper->info('LGL MembershipRenewalAction: Membership renewal completed (async)', [
                    'user_id' => $uid,
                    'order_id' => $order_id
                ]);
            } catch (\Exception $e) {
                $this->helper->warning('LGL MembershipRenewalAction: Async scheduling failed, falling back to sync', [
                    'error' => $e->getMessage(),
                    'user_id' => $uid
                ]);
                
                // Fallback to synchronous processing if async fails
                $this->processLGLMembershipRenewal($uid, $email, $order_id, $price, $date);
                $this->helper->info('LGL MembershipRenewalAction: Membership renewal completed (sync)', [
                    'user_id' => $uid,
                    'order_id' => $order_id
                ]);
            }
        } else {
            // Fallback: synchronous processing if async processor not available
            $this->processLGLMembershipRenewal($uid, $email, $order_id, $price, $date);
            $this->helper->info('LGL MembershipRenewalAction: Membership renewal completed (sync)', [
                'user_id' => $uid,
                'order_id' => $order_id
            ]);
        }
            
        } catch (\Exception $e) {
            $this->helper->error('LGL MembershipRenewalAction: Error occurred', [
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
     * Update renewal date
     * 
     * @param int $uid User ID
     * @return void
     */
    private function updateRenewalDate(int $uid): void {
        $current_start_date = get_user_meta($uid, 'user-membership-start-date', true);
        $new_date = strtotime('+1 year', $current_start_date);
        
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
            $this->helper->changeUserRole($uid, 'ui_member', 'ui_patron_owner');
        } elseif (in_array($membership_level, ['Individual Membership', 'Patron Membership'])) {
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
        $existing_user = $this->connection->getConstituentData($user_lgl_id);
        
        if (!$existing_user) {
            $this->helper->error('LGL MembershipRenewalAction: No existing LGL user found', [
                'user_id' => $uid,
                'lgl_id' => $user_lgl_id
            ]);
            $error_message = 'Unable to renew membership. Your account was not found. Please contact support.';
            
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
            } else {
                throw new \RuntimeException($error_message);
            }
        }
        
        $lgl_id = is_array($existing_user) ? ($existing_user['id'] ?? null) : ($existing_user->id ?? null);
        
        if (!$lgl_id) {
            $this->helper->error('LGL MembershipRenewalAction: User ID not found', [
                'user_id' => $uid
            ]);
            $error_message = 'Unable to renew membership. User ID not found. Please contact support.';
            
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
            $this->helper->error('LGL MembershipRenewalAction: User account details not found', [
                'user_id' => $uid,
                'lgl_id' => $lgl_id,
                'email' => $email
            ]);
            $error_message = 'Unable to renew membership. User account details not found. Please contact support.';
            
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
                'note' => 'Membership DEACTIVATED via WP_LGL_API on ' . date('Y-m-d')
            ];
            
            // Use modern Connection::updateMembership() - uses direct membership endpoint
            $result = $this->connection->updateMembership((string)$mid, $updated_membership);
            
            if (empty($result['success'])) {
                $this->helper->error('LGL MembershipRenewalAction: Failed to update membership', [
                    'membership_id' => $mid,
                    'lgl_id' => $lgl_id,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
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
        $membership_data = $this->constituents->getMembershipData();
        
        $result = $this->connection->addMembership($lgl_id, $membership_data);
        
        if (!empty($result['success'])) {
            $this->helper->info('LGL MembershipRenewalAction: Renewal membership added', [
                'lgl_id' => $lgl_id,
                'user_id' => $uid
            ]);
        } else {
            $this->helper->error('LGL MembershipRenewalAction: Failed to add renewal membership', [
                'lgl_id' => $lgl_id,
                'user_id' => $uid,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
        }
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
        
        if ($payment_data && isset($payment_data['success']) && $payment_data['success']) {
            // Payment already added by setupMembershipPayment() - just log the result
            $payment_id = $payment_data['id'] ?? null;
            
            if ($payment_id) {
                $this->helper->info('LGL MembershipRenewalAction: Renewal payment added', [
                    'payment_id' => $payment_id,
                    'lgl_id' => $lgl_id,
                    'order_id' => $order_id
                ]);
            } else {
                $this->helper->warning('LGL MembershipRenewalAction: Payment created but no ID returned', [
                    'lgl_id' => $lgl_id,
                    'order_id' => $order_id
                ]);
            }
        } else {
            $error = $payment_data['error'] ?? 'Unknown error';
            $this->helper->error('LGL MembershipRenewalAction: Failed to create payment', [
                'lgl_id' => $lgl_id,
                'order_id' => $order_id,
                'error' => $error
            ]);
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
                $this->helper->debug("LGL MembershipRenewalAction: Missing required field", ['field' => $field]);
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('LGL MembershipRenewalAction: Invalid user_id', ['user_id' => $request['user_id'] ?? null]);
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
}
