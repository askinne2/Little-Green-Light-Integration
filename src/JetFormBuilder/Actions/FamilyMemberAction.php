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

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;

/**
 * FamilyMemberAction Class
 * 
 * Handles family member registration with parent membership inheritance
 */
class FamilyMemberAction implements JetFormActionInterface {
    
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
     * Handle family member addition action (DIRECT CREATION with security layers)
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        // Validate request data (Security Layer 1 & 2: Form access + Slot validation)
        if (!$this->validateRequest($request, $action_handler)) {
            $this->helper->debug('FamilyMemberAction: Invalid request data');
            return;
        }
        
        $parent_uid = (int) $request['parent_user_id'];
        $email = sanitize_email($request['email']);
        $first_name = sanitize_text_field($request['first_name']);
        $last_name = sanitize_text_field($request['last_name']);
        
        $this->helper->debug('FamilyMemberAction: Processing direct creation request', [
            'parent_uid' => $parent_uid,
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name
        ]);
        
        // Security Layer 3: Check if user already exists (prevent duplicates)
        if (email_exists($email)) {
            $existing_user = get_user_by('email', $email);
            
            $this->helper->debug('FamilyMemberAction: User already exists', [
                'email' => $email,
                'existing_user_id' => $existing_user->ID
            ]);
            
            // Exit gracefully - user already exists
            return;
        }
        
        // Create new WordPress user
        $child_uid = $this->createFamilyMemberUser($first_name, $last_name, $email);
        
        if (is_wp_error($child_uid)) {
            $this->helper->debug('FamilyMemberAction: User creation failed', [
                'error' => $child_uid->get_error_message()
            ]);
            
            return;
        }
        
        // Get parent membership info
        $parent_membership_info = $this->getParentMembershipInfo($parent_uid);
        
        // Inherit parent membership data
        $this->inheritParentMembership($child_uid, $parent_uid, $parent_membership_info);
        
        // Create JetEngine relationship
        $this->createFamilyRelationship($parent_uid, $child_uid);
        
        // Security Layer 5: Consume one family slot (economic rate limiting)
        $this->consumeFamilySlot($parent_uid);
        
        // Register in LGL CRM (must happen before role assignment as legacy code may modify roles)
        $child_request = $this->buildChildRequestForLGL($child_uid, $first_name, $last_name, $email, $parent_membership_info);
        $this->registerFamilyMember($child_request, $action_handler, $parent_uid);
        
        // Security Layer 4: Assign ui_patron_member role (hard-coded, cannot be overridden)
        // MUST BE AFTER LGL registration to prevent legacy code from overriding
        // Note: Role slug is ui_patron_member (display name: "UI Family Member")
        $this->assignFamilyMemberRole($child_uid);
        
        // Send welcome email with password reset link
        $this->sendWelcomeEmail($child_uid, $email, $first_name);
        
        $this->helper->debug('FamilyMemberAction: Successfully created family member', [
            'child_uid' => $child_uid,
            'parent_uid' => $parent_uid,
            'email' => $email
        ]);
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
        $this->helper->debug('FamilyMemberAction: Registering in LGL with modern UserRegistrationAction', [
            'child_uid' => $child_request['user_id'] ?? 'N/A',
            'parent_uid' => $parent_uid,
            'membership_type' => $child_request['ui-membership-type'] ?? 'N/A'
        ]);
        
        // Use modern UserRegistrationAction instead of legacy LGL_API code
        $registration_action = new UserRegistrationAction($this->connection, $this->helper);
        $registration_action->handle($child_request, $action_handler);
        
        $this->helper->debug('FamilyMemberAction: LGL registration completed');
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
     * Validate request data before processing (Security Layers 1 & 2)
     * 
     * @param array $request Form data
     * @param mixed $action_handler JetFormBuilder action handler
     * @return bool
     */
    public function validateRequest(array $request, $action_handler = null): bool {
        $required_fields = $this->getRequiredFields();
        
        foreach ($required_fields as $field) {
            if (!isset($request[$field]) || empty($request[$field])) {
                $this->helper->debug("FamilyMemberAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate parent_user_id is numeric and positive
        if (!isset($request['parent_user_id']) || !is_numeric($request['parent_user_id']) || (int)$request['parent_user_id'] <= 0) {
            $this->helper->debug('FamilyMemberAction: Invalid parent_user_id');
            return false;
        }
        
        // SECURITY LAYER 2: Check parent has available slots
        $parent_uid = (int) $request['parent_user_id'];
        $available_slots = (int) get_user_meta($parent_uid, 'user_available_family_slots', true);
        
        if ($available_slots <= 0) {
            $this->helper->debug('FamilyMemberAction: No available family slots', [
                'parent_uid' => $parent_uid,
                'available_slots' => $available_slots
            ]);
            
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
            'parent_user_id',
            'first_name',
            'last_name',
            'email'
        ];
    }
    
    /**
     * Create new WordPress user for family member
     * 
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param string $email Email address
     * @return int|\WP_Error User ID or WP_Error on failure
     */
    private function createFamilyMemberUser(string $first_name, string $last_name, string $email) {
        $username = $this->generateUsername($first_name, $last_name);
        $password = wp_generate_password(12, true, true);
        
        $user_data = [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
            'role' => 'subscriber' // Temporary, will be changed to ui_patron_member
        ];
        
        $user_id = wp_insert_user($user_data);
        
        if (!is_wp_error($user_id)) {
            $this->helper->debug('FamilyMemberAction: User created', [
                'user_id' => $user_id,
                'username' => $username,
                'email' => $email
            ]);
        }
        
        return $user_id;
    }
    
    /**
     * Generate unique username from first and last name
     * 
     * @param string $first_name First name
     * @param string $last_name Last name
     * @return string Unique username
     */
    private function generateUsername(string $first_name, string $last_name): string {
        $base_username = strtolower(sanitize_user($first_name . $last_name));
        $username = $base_username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Assign ui_patron_member role (Security Layer 4 - hard-coded for security)
     * Note: "UI Family Member" role slug is ui_patron_member
     * 
     * @param int $child_uid Child user ID
     * @return void
     */
    private function assignFamilyMemberRole(int $child_uid): void {
        $user = new \WP_User($child_uid);
        $user->set_role('ui_patron_member'); // HARD-CODED role for family members
        
        $this->helper->debug('FamilyMemberAction: Assigned ui_patron_member role (UI Family Member)', [
            'child_uid' => $child_uid,
            'role' => 'ui_patron_member'
        ]);
    }
    
    /**
     * Create JetEngine family relationship (Relation ID 24)
     * 
     * @param int $parent_uid Parent user ID
     * @param int $child_uid Child user ID
     * @return void
     */
    private function createFamilyRelationship(int $parent_uid, int $child_uid): void {
        if (function_exists('jet_engine')) {
            $relation = \jet_engine()->relations->get_active_relations(24);
            if ($relation) {
                // Add relationship using JetEngine's update() method
                $result = $relation->update($parent_uid, $child_uid);
                
                // Get updated children count
                $children = $relation->get_children($parent_uid, 'ids');
                
                $this->helper->debug('FamilyMemberAction: Created family relationship', [
                    'parent_uid' => $parent_uid,
                    'child_uid' => $child_uid,
                    'relation_id' => 24,
                    'total_children' => is_array($children) ? count($children) : 0,
                    'result' => $result
                ]);
            } else {
                $this->helper->debug('FamilyMemberAction: Could not get JetEngine relation 24');
            }
        } else {
            $this->helper->debug('FamilyMemberAction: JetEngine not available');
        }
    }
    
    /**
     * Consume one family slot (Security Layer 5 - economic rate limiting)
     * 
     * @param int $parent_uid Parent user ID
     * @return void
     */
    private function consumeFamilySlot(int $parent_uid): void {
        $current_slots = (int) get_user_meta($parent_uid, 'user_available_family_slots', true);
        $new_slots = max(0, $current_slots - 1);
        update_user_meta($parent_uid, 'user_available_family_slots', $new_slots);
        
        $used_slots = (int) get_user_meta($parent_uid, 'user_used_family_slots', true);
        update_user_meta($parent_uid, 'user_used_family_slots', $used_slots + 1);
        
        $this->helper->debug('FamilyMemberAction: Consumed family slot', [
            'parent_uid' => $parent_uid,
            'remaining_slots' => $new_slots,
            'total_used' => $used_slots + 1
        ]);
    }
    
    /**
     * Build child request data for LGL registration
     * 
     * @param int $child_uid Child user ID
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param string $email Email address
     * @param array $parent_membership_info Parent membership information
     * @return array Child request data
     */
    private function buildChildRequestForLGL(
        int $child_uid,
        string $first_name,
        string $last_name,
        string $email,
        array $parent_membership_info
    ): array {
        return [
            'user_id' => $child_uid,
            'user_firstname' => $first_name,
            'user_lastname' => $last_name,
            'username' => $first_name . ' ' . $last_name,
            'user_email' => $email,
            'user_phone' => '',
            'user-address-1' => '',
            'user-address-2' => '',
            'user-city' => '',
            'user-state' => '',
            'user-postal-code' => '',
            'ui-membership-type' => $parent_membership_info['membership_type'],
            'method' => 'family_member', // Flag to prevent payment object creation
        ];
    }
    
    /**
     * Send welcome email with password reset link
     * 
     * @param int $child_uid Child user ID
     * @param string $email Email address
     * @param string $first_name First name
     * @return void
     */
    private function sendWelcomeEmail(int $child_uid, string $email, string $first_name): void {
        $reset_key = get_password_reset_key(new \WP_User($child_uid));
        
        if (is_wp_error($reset_key)) {
            $this->helper->debug('FamilyMemberAction: Failed to generate reset key', [
                'error' => $reset_key->get_error_message()
            ]);
            return;
        }
        
        $user = get_userdata($child_uid);
        $reset_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($user->user_login), 'login');
        
        // Load email template
        $template_path = LGL_PLUGIN_DIR . 'form-emails/family-member-welcome.html';
        $email_content = file_get_contents($template_path);
        
        if ($email_content === false) {
            $this->helper->debug('FamilyMemberAction: Email template not found', [
                'template_path' => $template_path
            ]);
            return;
        }
        
        // Replace placeholders
        $replacements = [
            '%user_firstname%' => $first_name,
            '%password_reset_url%' => $reset_url,
            '%site_url%' => home_url()
        ];
        
        $email_content = str_replace(array_keys($replacements), array_values($replacements), $email_content);
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $subject = 'Welcome to Upstate International - Set Your Password';
        
        $mail_sent = wp_mail($email, $subject, $email_content, $headers);
        
        $this->helper->debug('FamilyMemberAction: Welcome email sent', [
            'email' => $email,
            'user_id' => $child_uid,
            'mail_sent' => $mail_sent
        ]);
    }
}
