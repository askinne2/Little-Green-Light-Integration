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
     * Constructor
     * 
     * @param Connection $connection LGL connection service
     * @param Helper $helper LGL helper service
     * @param Constituents $constituents LGL constituents service
     */
    public function __construct(
        Connection $connection,
        Helper $helper,
        Constituents $constituents
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->constituents = $constituents;
    }
    
    /**
     * Handle user edit action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('UserEditAction: Invalid request data', $request);
            return;
        }
        
        $this->helper->debug('UserEditAction: Processing request', $request);
        
        $uid = (int) $request['user_id'];
        $user_name = $request['user_firstname'] . ' ' . $request['user_lastname'];
        $user_email = $request['user_email'];
        
        // Get existing LGL user
        $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
        $existing_user = $this->connection->getLglData('SINGLE_CONSTITUENT', $user_lgl_id);
        
        if ($existing_user) {
            $this->updateExistingUser($existing_user, $uid, $request);
        } else {
            $this->createNewUserFromEdit($uid, $request, $user_name, $user_email);
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
        $existing_contact_id = $existing_user->id;
        $this->helper->debug('LGL USER EXISTS: ', $existing_contact_id);
        
        if (!$existing_contact_id) {
            $this->helper->debug('UserEditAction: No valid contact ID found');
            return;
        }
        
        // Prepare update data using Constituents service
        $update_data = $this->constituents->setDataAndUpdate($uid, $request);
        
        if (!$update_data) {
            $this->helper->debug('UserEditAction: Failed to prepare update data');
            return;
        }
        
        // Update contact in LGL
        $response = $this->connection->updateLglObject($existing_contact_id, $update_data, '.json');
        
        if ($response) {
            $this->helper->debug('UPDATED CONTACT', $existing_contact_id);
        } else {
            $this->helper->debug('FAILED TO UPDATE contact', $existing_contact_id);
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
        $this->helper->debug('NO Contact Found, UserEditAction', $user_name . '   ' . $user_email);
        
        // Try to search for existing contact by name and email
        $username = str_replace(' ', '%20', $request['user_firstname'] . ' ' . $request['user_lastname']);
        $emails = $this->collectCandidateEmails($uid, $request);
        $match = $this->connection->searchByName($username, $emails);
        $lgl_id = $match['id'] ?? null;
        
        if (!$lgl_id) {
            // Create new constituent if not found
            $lgl_id = $this->createSimpleConstituent($uid, $request);
            
            if ($lgl_id) {
                $this->helper->debug('Created new Constituent LGL ID: ', $lgl_id);
                update_user_meta($uid, 'lgl_id', $lgl_id);
            } else {
                $this->helper->debug('UserEditAction: Failed to create new constituent');
            }
        } else {
            // Link existing LGL constituent to WordPress user
            update_user_meta($uid, 'lgl_id', $lgl_id);
            $this->helper->debug('Linked existing LGL constituent: ', $lgl_id);
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
                $this->helper->debug("UserEditAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('UserEditAction: Invalid user_id');
            return false;
        }
        
        // Validate email format
        if (isset($request['user_email']) && !filter_var($request['user_email'], FILTER_VALIDATE_EMAIL)) {
            $this->helper->debug('UserEditAction: Invalid email format');
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
            return $response['data']['id'] ?? false;
        } catch (Exception $e) {
            $this->helper->debug('UserEditAction: Exception creating constituent', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
