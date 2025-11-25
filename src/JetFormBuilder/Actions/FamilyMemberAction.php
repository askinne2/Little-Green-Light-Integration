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
use UpstateInternational\LGL\Memberships\MembershipRegistrationService;
use UpstateInternational\LGL\JetFormBuilder\AsyncFamilyMemberProcessor;

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
     * Membership Registration Service
     * 
     * @var MembershipRegistrationService
     */
    private MembershipRegistrationService $registrationService;
    
    /**
     * Async Family Member Processor
     * 
     * @var AsyncFamilyMemberProcessor|null
     */
    private ?AsyncFamilyMemberProcessor $asyncProcessor = null;
    
    /**
     * Constructor
     * 
     * @param Connection $connection LGL connection service
     * @param Helper $helper LGL helper service
     */
    public function __construct(Connection $connection, Helper $helper) {
        $this->connection = $connection;
        $this->helper = $helper;
        
        // Get MembershipRegistrationService from container
        $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
        if ($container->has('memberships.registration_service')) {
            $this->registrationService = $container->get('memberships.registration_service');
        } else {
            // Fallback: instantiate directly if container doesn't have it
            $this->registrationService = new MembershipRegistrationService(
                $this->connection,
                $this->helper,
                \UpstateInternational\LGL\LGL\Constituents::getInstance(),
                \UpstateInternational\LGL\LGL\Payments::getInstance()
            );
        }
        
        // Get AsyncFamilyMemberProcessor from container (optional - async processing)
        if ($container->has('jetformbuilder.async_family_processor')) {
            $this->asyncProcessor = $container->get('jetformbuilder.async_family_processor');
        }
    }
    
    /**
     * Handle family member addition action (DIRECT CREATION with security layers)
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
            $this->helper->info('LGL FamilyMemberAction: Processing family member addition', [
                'parent_user_id' => $request['parent_user_id'] ?? 'N/A',
                'email' => $request['email'] ?? 'N/A'
            ]);
            
            // SECURITY LAYER 0: Check if email already exists FIRST (before any processing)
            if (!empty($request['email'])) {
                $email = sanitize_email($request['email']);
                if (email_exists($email)) {
                    $existing_user = get_user_by('email', $email);
                    
                    $this->helper->error('LGL FamilyMemberAction: User already exists', [
                        'email' => $email,
                        'existing_user_id' => $existing_user->ID
                    ]);
                    
                    // Use Action_Exception for proper JetFormBuilder error handling
                    $error_message = sprintf(
                        'A user with the email address %s already exists. Please use a different email address or remove the existing family member first.',
                        $email
                    );
                    
                    if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                        throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                    } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                        throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                    } else {
                        throw new \RuntimeException($error_message);
                    }
                }
            }
            
            // SECURITY LAYER 1: Check parent has available slots BEFORE any processing
            // This must be checked here, not in validateRequest(), because validateRequest()
            // may be called by the filter hook AFTER the action has already consumed slots
            if (!empty($request['parent_user_id'])) {
                $parent_uid = (int) $request['parent_user_id'];
                $actual_used = $this->helper->getActualUsedFamilySlots($parent_uid);
                $max_allowed = \UpstateInternational\LGL\LGL\Helper::MAX_FAMILY_MEMBERS;
                
                // Check hard maximum first
                if ($actual_used >= $max_allowed) {
                    $this->helper->error('LGL FamilyMemberAction: Maximum family members reached', [
                        'parent_uid' => $parent_uid,
                        'actual_used' => $actual_used,
                        'max_allowed' => $max_allowed
                    ]);
                    
                    $error_message = sprintf(
                        'You have reached the maximum limit of %d family members. Please remove a family member before adding another.',
                        $max_allowed
                    );
                    
                    if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                        throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                    } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                        throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                    } else {
                        throw new \RuntimeException($error_message);
                    }
                }
                
                // Then check available slots (purchased slots)
                $available_slots = $this->helper->getAvailableFamilySlots($parent_uid);
                
                if ($available_slots <= 0) {
                    $this->helper->error('LGL FamilyMemberAction: No available family slots', [
                        'parent_uid' => $parent_uid,
                        'available_slots' => $available_slots
                    ]);
                    
                    $error_message = 'You do not have any available family member slots. Please purchase additional slots before adding family members.';
                    
                    if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                        throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                    } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                        throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                    } else {
                        throw new \RuntimeException($error_message);
                    }
                }
            }
            
            // Validate request data (Security Layer 2: Form field validation)
            if (!$this->validateRequest($request, $action_handler)) {
                $this->helper->error('LGL FamilyMemberAction: Invalid request data', [
                    'missing_fields' => $this->getMissingFields($request)
                ]);
                $error_message = 'Invalid request data. Please check that all required fields are filled correctly.';
                
                // Use Action_Exception for proper JetFormBuilder error handling
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
            }
            
        $parent_uid = (int) $request['parent_user_id'];
            $email = sanitize_email($request['email']);
            $first_name = sanitize_text_field($request['first_name']);
            $last_name = sanitize_text_field($request['last_name']);
            
            // Create new WordPress user
            $child_uid = $this->createFamilyMemberUser($first_name, $last_name, $email);
            
            if (is_wp_error($child_uid)) {
                $error_message = sprintf('Failed to create family member account: %s', $child_uid->get_error_message());
                $this->helper->error('LGL FamilyMemberAction: User creation failed', [
                    'error' => $error_message,
                    'email' => $email
                ]);
                
                // Use Action_Exception for proper JetFormBuilder error handling
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
            }
        
        // Get parent membership info
        $parent_membership_info = $this->getParentMembershipInfo($parent_uid);
        
        // Inherit parent membership data
        $this->inheritParentMembership($child_uid, $parent_uid, $parent_membership_info);
        
        // Create JetEngine relationship
        $this->createFamilyRelationship($parent_uid, $child_uid);
        
        // Security Layer 5: Consume one family slot (economic rate limiting)
        $this->consumeFamilySlot($parent_uid);
        
        // Register in LGL CRM (async processing - non-blocking)
        // Immediate tasks (WordPress user, JetEngine relationship) are done synchronously
        // LGL API calls are queued for async processing to speed up form submission
        $child_request = $this->buildChildRequestForLGL($child_uid, $first_name, $last_name, $email, $parent_membership_info);
        $this->registerFamilyMember($child_request, $action_handler, $parent_uid);
        
        // Security Layer 4: Assign ui_patron_member role (hard-coded, cannot be overridden)
        // MUST BE AFTER LGL registration to prevent legacy code from overriding
        // Note: Role slug is ui_patron_member (display name: "UI Family Member")
        $this->assignFamilyMemberRole($child_uid);
        
        // Send welcome email with password reset link
        $this->sendWelcomeEmail($child_uid, $email, $first_name);
        
            $this->helper->info('LGL FamilyMemberAction: Successfully created family member', [
                'child_uid' => $child_uid,
                'parent_uid' => $parent_uid,
                'email' => $email
            ]);
            
        } catch (\Exception $e) {
            $this->helper->error('LGL FamilyMemberAction: Error occurred', [
                'error' => $e->getMessage(),
                'parent_uid' => $parent_uid ?? null
            ]);
            
            // Re-throw exception so JetFormBuilder can handle it
            throw $e;
        }
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
    }
    
    /**
     * Register family member in LGL CRM
     * 
     * Uses async processing to speed up form submission. Immediate tasks (WordPress user,
     * JetEngine relationship) are done synchronously, but LGL API calls are queued for
     * background processing via WP Cron.
     * 
     * @param array $child_request Child request data
     * @param mixed $action_handler JetFormBuilder action handler
     * @param int $parent_uid Parent user ID
     * @return void
     */
    private function registerFamilyMember(array $child_request, $action_handler, int $parent_uid): void {
        $child_uid = (int) ($child_request['user_id'] ?? 0);
        
        // Build context array for MembershipRegistrationService
        $context = [
            'user_id' => $child_uid,
            'search_name' => ($child_request['user_firstname'] ?? '') . '%20' . ($child_request['user_lastname'] ?? ''),
            'emails' => !empty($child_request['user_email']) ? [strtolower($child_request['user_email'])] : [],
            'email' => $child_request['user_email'] ?? '',
            'order_id' => 0, // Family members don't have orders
            'price' => 0, // Family members don't pay
            'membership_level' => $child_request['ui-membership-type'] ?? '',
            'membership_level_id' => null,
            'payment_type' => null,
            'is_family_member' => true, // CRITICAL: This prevents payment creation
            'request' => $child_request,
            'order' => null
        ];
        
        // Use async processing if available (speeds up form submission)
        if ($this->asyncProcessor) {
            $this->helper->debug('LGL FamilyMemberAction: Scheduling async LGL processing', [
                'child_uid' => $child_uid
            ]);
            
            try {
                $this->asyncProcessor->scheduleAsyncProcessing($child_uid, $parent_uid, $context);
                
                $this->helper->info('LGL FamilyMemberAction: Family member registration completed (async)', [
                    'child_uid' => $child_uid,
                    'parent_uid' => $parent_uid
                ]);
            } catch (\Exception $e) {
                $this->helper->warning('LGL FamilyMemberAction: Async scheduling failed, falling back to sync', [
                    'error' => $e->getMessage(),
                    'child_uid' => $child_uid
                ]);
                
                // Fallback to synchronous processing if async fails
                $this->registerFamilyMemberSync($context, $parent_uid, $child_uid);
                $this->helper->info('LGL FamilyMemberAction: Family member registration completed (sync)', [
                    'child_uid' => $child_uid,
                    'parent_uid' => $parent_uid
                ]);
            }
        } else {
            // Fallback: synchronous processing if async processor not available
            $this->registerFamilyMemberSync($context, $parent_uid, $child_uid);
            $this->helper->info('LGL FamilyMemberAction: Family member registration completed (sync)', [
                'child_uid' => $child_uid,
                'parent_uid' => $parent_uid
            ]);
        }
    }
    
    /**
     * Register family member synchronously (fallback method)
     * 
     * @param array $context Context data for MembershipRegistrationService
     * @param int $parent_uid Parent user ID
     * @param int $child_uid Child user ID
     * @return void
     */
    private function registerFamilyMemberSync(array $context, int $parent_uid, int $child_uid): void {
        try {
            $result = $this->registrationService->register($context);
            
            // Create LGL constituent relationship (Parent/Child) after successful registration
            if (!empty($result['lgl_id'])) {
                $child_lgl_id = (int) $result['lgl_id'];
                $this->createLGLRelationship($parent_uid, $child_uid, $child_lgl_id);
            }
        } catch (\Exception $e) {
            $this->helper->error('LGL FamilyMemberAction: LGL registration failed', [
                'error' => $e->getMessage(),
                'child_uid' => $child_uid,
                'parent_uid' => $parent_uid
            ]);
            
            // Re-throw as Action_Exception for proper form error handling
            $error_message = 'Failed to register family member in LGL. Please try again or contact support.';
            
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
                $this->helper->debug("LGL FamilyMemberAction: Missing required field", ['field' => $field]);
                return false;
            }
        }
        
        // Validate parent_user_id is numeric and positive
        if (!isset($request['parent_user_id']) || !is_numeric($request['parent_user_id']) || (int)$request['parent_user_id'] <= 0) {
            $this->helper->debug('LGL FamilyMemberAction: Invalid parent_user_id', ['parent_user_id' => $request['parent_user_id'] ?? null]);
            return false;
        }
        
        // NOTE: Slot availability check is done in handle() method FIRST, not here.
        // This is because validateRequest() may be called by the filter hook AFTER
        // the action has already consumed slots, causing false negatives.
        
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
                
            } else {
                $this->helper->debug('LGL FamilyMemberAction: Could not get JetEngine relation 24');
            }
        } else {
            $this->helper->debug('LGL FamilyMemberAction: JetEngine not available');
        }
    }
    
    /**
     * Consume one family slot (Security Layer 5 - economic rate limiting)
     * 
     * Note: We don't manually increment user_used_family_slots anymore.
     * Instead, we sync it with the actual JetEngine relationship count.
     * Available slots are recalculated based on: total_purchased - actual_used
     * 
     * @param int $parent_uid Parent user ID
     * @return void
     */
    private function consumeFamilySlot(int $parent_uid): void {
        // Sync user_used_family_slots with actual JetEngine count (source of truth)
        $this->helper->syncUsedFamilySlotsMeta($parent_uid);
        
        // Recalculate available slots based on actual count: total_purchased - actual_used
        $total_purchased = (int) get_user_meta($parent_uid, 'user_total_family_slots_purchased', true);
        $actual_used = $this->helper->getActualUsedFamilySlots($parent_uid);
        $new_available = $total_purchased - $actual_used;
        update_user_meta($parent_uid, 'user_available_family_slots', max(0, $new_available));
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
     * Create LGL constituent relationships (Parent/Child - both directions)
     * 
     * Creates bidirectional Parent/Child relationships in LGL CRM:
     * 1. Child -> Parent (relationship type: "Parent")
     * 2. Parent -> Child (relationship type: "Child")
     * 
     * Stores relationship IDs in WordPress user meta for easy deletion later.
     * 
     * WordPress User Meta Fields:
     *   - 'lgl_family_relationship_id': Stores Child->Parent relationship ID (on child user)
     *   - 'lgl_family_relationship_parent_id': Stores Parent->Child relationship ID (on child user, for parent's reference)
     * 
     * @param int $parent_uid Parent WordPress user ID
     * @param int $child_uid Child WordPress user ID
     * @param int $child_lgl_id Child LGL constituent ID
     * @return void
     */
    private function createLGLRelationship(int $parent_uid, int $child_uid, int $child_lgl_id): void {
        $parent_lgl_id = get_user_meta($parent_uid, 'lgl_id', true);
        
        if (!$parent_lgl_id) {
            $this->helper->debug('LGL FamilyMemberAction: Parent LGL ID not found, skipping relationship creation', [
                'parent_uid' => $parent_uid
            ]);
            return;
        }
        
        // Look up relationship type IDs
        $parent_type_id = $this->connection->getRelationshipTypeId('Parent');
        $child_type_id = $this->connection->getRelationshipTypeId('Child');
        
        if (!$parent_type_id || !$child_type_id) {
            $this->helper->debug('LGL FamilyMemberAction: Could not find relationship type IDs', [
                'child_lgl_id' => $child_lgl_id,
                'parent_lgl_id' => $parent_lgl_id
            ]);
            return; // Don't create relationships if we can't find the types
        }
        
        // Create relationship from child's perspective: Child -> Parent
        try {
            $child_to_parent_data = [
                'related_constituent_id' => (int) $parent_lgl_id,
                'relationship_type_id' => $parent_type_id
            ];
            
            $response = $this->connection->createConstituentRelationship($child_lgl_id, $child_to_parent_data);
            
            if ($response['success'] && isset($response['data'])) {
                $relationship_id = is_object($response['data']) ? 
                    ($response['data']->id ?? null) : 
                    ($response['data']['id'] ?? null);
                
                if ($relationship_id) {
                    // Store Child->Parent relationship ID on child user
                    update_user_meta($child_uid, 'lgl_family_relationship_id', (int) $relationship_id);
                    
                    $this->helper->debug('LGL FamilyMemberAction: Created Child->Parent LGL relationship', [
                        'child_lgl_id' => $child_lgl_id,
                        'parent_lgl_id' => $parent_lgl_id,
                        'relationship_id' => $relationship_id
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->helper->error('LGL FamilyMemberAction: Exception creating Child->Parent relationship', [
                'error' => $e->getMessage(),
                'child_lgl_id' => $child_lgl_id,
                'parent_lgl_id' => $parent_lgl_id
            ]);
        }
        
        // Create reciprocal relationship from parent's perspective: Parent -> Child
        try {
            $parent_to_child_data = [
                'related_constituent_id' => (int) $child_lgl_id,
                'relationship_type_id' => $child_type_id
            ];
            
            $response = $this->connection->createConstituentRelationship($parent_lgl_id, $parent_to_child_data);
            
            if ($response['success'] && isset($response['data'])) {
                $relationship_id = is_object($response['data']) ? 
                    ($response['data']->id ?? null) : 
                    ($response['data']['id'] ?? null);
                
                if ($relationship_id) {
                    // Store Parent->Child relationship ID on child user (for reference during deletion)
                    update_user_meta($child_uid, 'lgl_family_relationship_parent_id', (int) $relationship_id);
                    
                    $this->helper->debug('LGL FamilyMemberAction: Created Parent->Child LGL relationship', [
                        'child_lgl_id' => $child_lgl_id,
                        'parent_lgl_id' => $parent_lgl_id,
                        'relationship_id' => $relationship_id
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->helper->error('LGL FamilyMemberAction: Exception creating Parent->Child relationship', [
                'error' => $e->getMessage(),
                'child_lgl_id' => $child_lgl_id,
                'parent_lgl_id' => $parent_lgl_id
            ]);
            // Don't throw - relationship creation failure shouldn't prevent family member creation
        }
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
            $this->helper->error('LGL FamilyMemberAction: Failed to generate reset key', [
                'error' => $reset_key->get_error_message(),
                'child_uid' => $child_uid
            ]);
            return;
        }
        
        $user = get_userdata($child_uid);
        $reset_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($user->user_login), 'login');
        
        try {
            // Use WooCommerce email class if available
            if (class_exists('WC_Email') && class_exists('\UpstateInternational\LGL\Email\WC_Family_Member_Welcome_Email')) {
                $wc_email = new \UpstateInternational\LGL\Email\WC_Family_Member_Welcome_Email();
                $wc_email->trigger($email, $first_name, $reset_url);
            } else {
                // Fallback to wp_mail if WooCommerce not available
                $template_path = LGL_PLUGIN_DIR . 'form-emails/family-member-welcome.html';
                $email_content = file_get_contents($template_path);
                
                if ($email_content === false) {
                    $this->helper->error('LGL FamilyMemberAction: Email template not found', [
                        'template_path' => $template_path,
                        'child_uid' => $child_uid
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
                
                wp_mail($email, $subject, $email_content, $headers);
            }
        } catch (\Exception $e) {
            $this->helper->error('LGL FamilyMemberAction: Email sending exception', [
                'error' => $e->getMessage(),
                'child_uid' => $child_uid
            ]);
        }
    }
}
