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
use UpstateInternational\LGL\JetFormBuilder\AsyncJetFormProcessor;

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
     * @param Payments $payments LGL payments service
     * @param AsyncJetFormProcessor|null $asyncProcessor Async processor (optional)
     */
    public function __construct(
        Connection $connection,
        Helper $helper,
        Payments $payments,
        ?AsyncJetFormProcessor $asyncProcessor = null
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
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
     * Handle event registration action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('EventRegistrationAction: Invalid request data', $request);
                $error_message = 'Invalid request data. Please check that all required fields are filled correctly.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
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
                $error_message = 'User ID or username is missing. Please ensure you are logged in and try again.';
                
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
            $this->helper->debug('EventRegistrationAction: User not found', $uid);
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
        
        // Use async processing if available (speeds up form submission)
        if ($this->asyncProcessor) {
            $this->helper->debug('⏰ EventRegistrationAction: Scheduling async LGL processing', [
                'user_id' => $uid,
                'event_name' => $event_name
            ]);
            
            try {
                $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
                $context = [
                    'lgl_id' => $user_lgl_id,
                    'event_name' => $event_name,
                    'order_id' => $order_id,
                    'price' => $price,
                    'date' => $date,
                    'lgl_fund_id' => $lgl_fund_id
                ];
                
                $this->asyncProcessor->scheduleAsyncProcessing('event_registration', $uid, $context);
                
                $this->helper->debug('✅ EventRegistrationAction: Async LGL processing scheduled', [
                    'user_id' => $uid,
                    'note' => 'LGL API calls will be processed in background via WP Cron'
                ]);
            } catch (\Exception $e) {
                $this->helper->debug('⚠️ EventRegistrationAction: Async scheduling failed, falling back to sync', [
                    'error' => $e->getMessage(),
                    'user_id' => $uid
                ]);
                
                // Fallback to synchronous processing if async fails
                $this->processEventRegistration($uid, $username, $email, $event_name, $order_id, $price, $date, $lgl_fund_id);
            }
        } else {
            // Fallback: synchronous processing if async processor not available
            $this->helper->debug('⚠️ EventRegistrationAction: Async processor not available, using sync', [
                'user_id' => $uid
            ]);
            
            $this->processEventRegistration($uid, $username, $email, $event_name, $order_id, $price, $date, $lgl_fund_id);
        }
            
        } catch (\Exception $e) {
            $this->helper->debug('EventRegistrationAction: Error occurred', [
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
     * Process event registration in LGL
     * 
     * @param int $uid User ID
     * @param string $username Username
     * @param string $email User email
     * @param string $event_name Event name
     * @param mixed $order_id Order ID
     * @param float $price Event price
     * @param string $date Registration date
     * @param string $lgl_fund_id LGL fund ID (deprecated - fund ID now determined internally by payment method)
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
        $existing_user = $this->connection->getConstituentData($user_lgl_id);
        
        if (!$existing_user) {
            $this->helper->debug('EventRegistrationAction: No existing LGL user found for event registration', $username);
            $error_message = 'Unable to register for event. Your account was not found. Please contact support.';
            
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
            } else {
                throw new \RuntimeException($error_message);
            }
        }
        
        $lgl_id = is_array($existing_user) ? ($existing_user['id'] ?? null) : ($existing_user->id ?? null);
        $this->helper->debug('LGL USER EXISTS: ', $lgl_id);
        
        if (!$lgl_id) {
            $this->helper->debug('Cannot find user with name, EventRegistrationAction', $username);
            $error_message = 'Unable to register for event. User ID not found. Please contact support.';
            
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
            } else {
                throw new \RuntimeException($error_message);
            }
        }
        
        // Setup event payment (fund ID determined internally by payment method)
        $payment_data = $this->payments->setupEventPayment(
            $lgl_id,
            $order_id,
            $price,
            $date,
            $event_name
        );
        
        $this->helper->debug('Event payment data: ', $payment_data);
        
        if ($payment_data && isset($payment_data['success']) && $payment_data['success']) {
            // Payment already added by setupEventPayment() - just log the result
            $payment_id = $payment_data['id'] ?? null;
            
            if ($payment_id) {
                $this->helper->debug('Event Payment ID: ', $payment_id);
            } else {
                $this->helper->debug('EventRegistrationAction: Payment created but no ID returned', $payment_data);
            }
        } else {
            $error = $payment_data['error'] ?? 'Unknown error';
            $this->helper->debug('EventRegistrationAction: Failed to create payment', $error);
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
