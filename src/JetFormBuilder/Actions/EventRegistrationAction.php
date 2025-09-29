<?php
/**
 * Event Registration Action
 * 
 * Handles event registration through JetFormBuilder forms.
 * Registers users for events and processes payments in LGL CRM.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder\Actions;

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Payments;

/**
 * EventRegistrationAction Class
 * 
 * Handles event registration and payment processing
 */
class EventRegistrationAction implements JetFormActionInterface {
    
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
     * @param Payments $payments LGL payments service
     */
    public function __construct(
        Connection $connection,
        Helper $helper,
        Payments $payments
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->payments = $payments;
    }
    
    /**
     * Handle event registration action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('EventRegistrationAction: Invalid request data', $request);
            return;
        }
        
        $this->helper->debug('EventRegistrationAction: Processing request', $request);
        
        $uid = (int) $request['user_id'];
        $event_id = $request['event_id'] ?? $request['class_id'] ?? null; // Support both field names
        $username = str_replace(' ', '%20', $request['username']);
        $event_name = $request['event_name'] ?? '';
        $order_id = $request['inserted_post_id'] ?? null;
        $price = (float) ($request['event_price'] ?? 0);
        $date = date('Y-m-d', time());
        $lgl_fund_id = $request['lgl_fund_id'] ?? '';
        
        if ($uid === 0 || empty($username)) {
            $this->helper->debug('No User ID or username in Request, EventRegistrationAction');
            return;
        }
        
        // Get user email
        $user_data = get_userdata($uid);
        if (!$user_data) {
            $this->helper->debug('EventRegistrationAction: User not found', $uid);
            return;
        }
        $email = $user_data->data->user_email;
        
        // Process event registration
        $this->processEventRegistration($uid, $username, $email, $event_name, $order_id, $price, $date, $lgl_fund_id);
    }
    
    /**
     * Process event registration in LGL
     * 
     * @param int $uid User ID
     * @param string $username Username
     * @param string $email User email
     * @param string $event_name Event name
     * @param mixed $order_id Order ID
     * @param float $price Event price
     * @param string $date Registration date
     * @param string $lgl_fund_id LGL fund ID
     * @return void
     */
    private function processEventRegistration(
        int $uid,
        string $username,
        string $email,
        string $event_name,
        $order_id,
        float $price,
        string $date,
        string $lgl_fund_id
    ): void {
        // Retrieve current user LGL ID
        $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
        $existing_user = $this->connection->getLglData('SINGLE_CONSTITUENT', $user_lgl_id);
        
        if (!$existing_user) {
            $this->helper->debug('EventRegistrationAction: No existing LGL user found for event registration', $username);
            return;
        }
        
        $lgl_id = $existing_user->id;
        $this->helper->debug('LGL USER EXISTS: ', $lgl_id);
        
        if (!$lgl_id) {
            $this->helper->debug('Cannot find user with name, EventRegistrationAction', $username);
            return;
        }
        
        // Setup event payment
        $payment_data = $this->payments->setupEventPayment(
            $this,
            $order_id,
            $price,
            $date,
            $event_name,
            $lgl_fund_id
        );
        
        $this->helper->debug('Event payment data: ', $payment_data);
        
        if ($payment_data) {
            // Add payment to LGL
            $payment_id = $this->connection->addLglObject($lgl_id, $payment_data, 'gifts.json');
            
            if ($payment_id) {
                $this->helper->debug('Event Payment ID: ', $payment_id);
            } else {
                $this->helper->debug('EventRegistrationAction: Failed to create payment');
            }
        } else {
            $this->helper->debug('EventRegistrationAction: Failed to setup payment data');
        }
    }
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string {
        return 'lgl_add_event_registration';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Register user for event and process payment in LGL CRM';
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
                $this->helper->debug("EventRegistrationAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('EventRegistrationAction: Invalid user_id');
            return false;
        }
        
        // Validate event_price is numeric
        if (isset($request['event_price']) && !is_numeric($request['event_price'])) {
            $this->helper->debug('EventRegistrationAction: Invalid event_price');
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
            'event_name'
        ];
    }
}
