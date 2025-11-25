<?php
/**
 * User Edit Action
 * 
 * Handles user profile editing through JetFormBuilder forms.
 * Updates existing user information in LGL CRM.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder\Actions;

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Constituents;
use UpstateInternational\LGL\JetFormBuilder\AsyncJetFormProcessor;

/**
 * UserEditAction Class
 * 
 * Handles user profile updates and synchronization with LGL CRM
 */
class UserEditAction implements JetFormActionInterface {
    
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
     * @param AsyncJetFormProcessor|null $asyncProcessor Async processor (optional)
     */
    public function __construct(
        Connection $connection,
        Helper $helper,
        Constituents $constituents,
        ?AsyncJetFormProcessor $asyncProcessor = null
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->constituents = $constituents;
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
     * Handle user edit action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
        $this->helper->info('LGL UserEditAction: Processing user profile update', [
            'user_id' => $request['user_id'] ?? 'N/A'
        ]);
        
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->error('LGL UserEditAction: Invalid request data', [
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
        $user_name = $request['user_firstname'] . ' ' . $request['user_lastname'];
        $user_email = $request['user_email'];
        
        // Get existing LGL user
        $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
        
        if ($user_lgl_id) {
            $existing_user = $this->connection->getConstituentData($user_lgl_id);
            
            if ($existing_user) {
                $this->updateExistingUser($existing_user, $uid, $request);
            } else {
                $this->createNewUserFromEdit($uid, $request, $user_name, $user_email);
            }
        } else {
            $this->createNewUserFromEdit($uid, $request, $user_name, $user_email);
            }
            
        } catch (\Exception $e) {
            $this->helper->error('LGL UserEditAction: Error occurred', [
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
     * Update existing LGL user
     * 
     * @param mixed $existing_user Existing LGL user data
     * @param int $uid WordPress user ID
     * @param array $request Form data
     * @return void
     */
    private function updateExistingUser($existing_user, int $uid, array $request): void {
        // Handle both object and array formats
        $existing_contact_id = is_object($existing_user) ? $existing_user->id : ($existing_user['id'] ?? null);
        
        if (!$existing_contact_id) {
            $this->helper->error('LGL UserEditAction: No valid contact ID found', [
                'user_id' => $uid
            ]);
            $error_message = 'Unable to update your profile. Contact ID not found. Please try again or contact support.';
            
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
            } else {
                throw new \RuntimeException($error_message);
            }
        }
        
        // For profile edits, skip membership updates unless explicitly provided in form
        // Profile edits should only update contact info (name, email, phone, address)
        $skip_membership = !isset($request['user-membership-type']) && !isset($request['membership_level']);
        
        // Use async processing if available (speeds up form submission)
        if ($this->asyncProcessor) {
            $this->helper->debug('LGL UserEditAction: Scheduling async LGL processing', [
                'user_id' => $uid
            ]);
            
            try {
                $context = [
                    'request' => $request,
                    'skip_membership' => $skip_membership
                ];
                
                $this->asyncProcessor->scheduleAsyncProcessing('user_edit', $uid, $context);
                
                $this->helper->info('LGL UserEditAction: User profile update completed (async)', [
                    'user_id' => $uid
                ]);
            } catch (\Exception $e) {
                $this->helper->warning('LGL UserEditAction: Async scheduling failed, falling back to sync', [
                    'error' => $e->getMessage(),
                    'user_id' => $uid
                ]);
                
                // Fallback to synchronous processing if async fails
                $this->updateConstituentSync($uid, $request, $skip_membership);
                $this->helper->info('LGL UserEditAction: User profile update completed (sync)', [
                    'user_id' => $uid
                ]);
            }
        } else {
            // Fallback: synchronous processing if async processor not available
            $this->updateConstituentSync($uid, $request, $skip_membership);
            $this->helper->info('LGL UserEditAction: User profile update completed (sync)', [
                'user_id' => $uid
            ]);
        }
    }
    
    /**
     * Update constituent synchronously (fallback method)
     * 
     * @param int $uid WordPress user ID
     * @param array $request Form data
     * @param bool $skip_membership Skip membership updates
     * @return void
     */
    private function updateConstituentSync(int $uid, array $request, bool $skip_membership): void {
        // Update constituent using Constituents service (handles update internally)
        $result = $this->constituents->setDataAndUpdate($uid, $request, $skip_membership);
        
        if ($result && isset($result['success']) && $result['success']) {
            $this->helper->info('LGL UserEditAction: Updated contact', [
                'user_id' => $uid
            ]);
        } else {
            $error = $result['error'] ?? 'Unknown error';
            $this->helper->error('LGL UserEditAction: Failed to update contact', [
                'user_id' => $uid,
                'error' => $error
            ]);
        }
    }

    /**
     * Collect candidate emails for constituent matching
     *
     * @param int $userId
     * @param array $request
     * @return array<string>
     */
    private function collectCandidateEmails(int $userId, array $request): array {
        $user = get_userdata($userId);
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
     * Create new LGL user from edit form (fallback)
     * 
     * @param int $uid WordPress user ID
     * @param array $request Form data
     * @param string $user_name Full user name
     * @param string $user_email User email
     * @return void
     */
    private function createNewUserFromEdit(int $uid, array $request, string $user_name, string $user_email): void {
        // Try to search for existing contact by name and email
        $username = str_replace(' ', '%20', $request['user_firstname'] . ' ' . $request['user_lastname']);
        $emails = $this->collectCandidateEmails($uid, $request);
        $match = $this->connection->searchByName($username, $emails);
        $lgl_id = $match['id'] ?? null;
        
        if (!$lgl_id) {
            // Create new constituent if not found
            $lgl_id = $this->createSimpleConstituent($uid, $request);
            
            if ($lgl_id) {
                $this->helper->info('LGL UserEditAction: Created new constituent', [
                    'lgl_id' => $lgl_id,
                    'user_id' => $uid
                ]);
                update_user_meta($uid, 'lgl_id', $lgl_id);
            } else {
                $this->helper->error('LGL UserEditAction: Failed to create new constituent', [
                    'user_id' => $uid
                ]);
            }
        } else {
            // Link existing LGL constituent to WordPress user
            update_user_meta($uid, 'lgl_id', $lgl_id);
            $this->helper->info('LGL UserEditAction: Linked existing LGL constituent', [
                'lgl_id' => $lgl_id,
                'user_id' => $uid
            ]);
        }
    }
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string {
        return 'lgl_edit_user';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Update user profile information in LGL CRM system';
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
                $this->helper->debug("LGL UserEditAction: Missing required field", ['field' => $field]);
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('LGL UserEditAction: Invalid user_id', ['user_id' => $request['user_id'] ?? null]);
            return false;
        }
        
        // Validate email format
        if (isset($request['user_email']) && !filter_var($request['user_email'], FILTER_VALIDATE_EMAIL)) {
            $this->helper->debug('LGL UserEditAction: Invalid email format', ['email' => $request['user_email'] ?? null]);
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
            'user_email'
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
     * Create a simple constituent for user edit scenarios
     * 
     * @param int $uid WordPress user ID
     * @param array $request Request data
     * @return int|false LGL constituent ID on success, false on failure
     */
    private function createSimpleConstituent(int $uid, array $request) {
        $user_info = get_userdata($uid);
        if (!$user_info) {
            return false;
        }
        
        // Build basic constituent data
        $first_name = $request['user_firstname'] ?? get_user_meta($uid, 'first_name', true) ?: $user_info->first_name;
        $last_name = $request['user_lastname'] ?? get_user_meta($uid, 'last_name', true) ?: $user_info->last_name;
        $email = $request['user_email'] ?? get_user_meta($uid, 'user_email', true) ?: $user_info->user_email;
        
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
            if ($response['success'] && isset($response['data']['id'])) {
                return $response['data']['id'];
            }
            return false;
        } catch (\Exception $e) {
            $this->helper->error('LGL UserEditAction: Exception creating constituent', [
                'user_id' => $uid,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
