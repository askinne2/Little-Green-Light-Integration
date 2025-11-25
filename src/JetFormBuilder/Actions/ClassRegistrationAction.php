<?php
/**
 * Class Registration Action
 * 
 * @deprecated 2.0.0 This action is deprecated. CourseStorm now handles all new language class registrations externally.
 *                   Legacy WooCommerce language class processing is maintained for backward compatibility only.
 *                   Kept for backward compatibility with legacy JetFormBuilder forms and internal WooCommerce processing.
 * 
 * Handles class registration through JetFormBuilder forms.
 * Registers users for language classes and processes payments in LGL CRM.
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
 * ClassRegistrationAction Class
 * 
 * @deprecated 2.0.0 CourseStorm handles new language class registrations externally.
 *                   This class is kept for backward compatibility only.
 * 
 * Handles language class registration and payment processing
 */
class ClassRegistrationAction implements JetFormActionInterface {
    
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
     * Handle class registration action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
        $this->helper->info('LGL ClassRegistrationAction: Processing class registration', [
            'user_id' => $request['user_id'] ?? 'N/A',
            'class_name' => $request['class_name'] ?? 'N/A'
        ]);
        
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->error('LGL ClassRegistrationAction: Invalid request data', [
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
        $class_id = $request['class_id'] ?? null;
        $username = str_replace(' ', '%20', $request['username']);
        $class_name = $request['class_name'] ?? '';
        $order_id = $request['inserted_post_id'] ?? null;
        $price = (float) ($request['class_price'] ?? 0);
        $date = date('Y-m-d', time());
        $lgl_fund_id = $request['lgl_fund_id'] ?? '';
        
        if ($uid === 0 || empty($username)) {
            $this->helper->error('LGL ClassRegistrationAction: No User ID or username in Request', [
                'request_keys' => array_keys($request)
            ]);
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
            $this->helper->error('LGL ClassRegistrationAction: User not found', [
                'user_id' => $uid
            ]);
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
            $this->helper->debug('LGL ClassRegistrationAction: Scheduling async LGL processing', [
                'user_id' => $uid
            ]);
            
            try {
                $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
                $context = [
                    'lgl_id' => $user_lgl_id,
                    'class_name' => $class_name,
                    'order_id' => $order_id,
                    'price' => $price,
                    'date' => $date,
                    'lgl_fund_id' => $lgl_fund_id
                ];
                
                $this->asyncProcessor->scheduleAsyncProcessing('class_registration', $uid, $context);
                
                $this->helper->info('LGL ClassRegistrationAction: Class registration completed (async)', [
                    'user_id' => $uid,
                    'class_name' => $class_name
                ]);
            } catch (\Exception $e) {
                $this->helper->warning('LGL ClassRegistrationAction: Async scheduling failed, falling back to sync', [
                    'error' => $e->getMessage(),
                    'user_id' => $uid
                ]);
                
                // Fallback to synchronous processing if async fails
                $this->processClassRegistration($uid, $username, $email, $class_name, $order_id, $price, $date, $lgl_fund_id);
                $this->helper->info('LGL ClassRegistrationAction: Class registration completed (sync)', [
                    'user_id' => $uid,
                    'class_name' => $class_name
                ]);
            }
        } else {
            // Fallback: synchronous processing if async processor not available
            $this->processClassRegistration($uid, $username, $email, $class_name, $order_id, $price, $date, $lgl_fund_id);
            $this->helper->info('LGL ClassRegistrationAction: Class registration completed (sync)', [
                'user_id' => $uid,
                'class_name' => $class_name
            ]);
        }
            
        } catch (\Exception $e) {
            $this->helper->error('LGL ClassRegistrationAction: Error occurred', [
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
     * Process class registration in LGL
     * 
     * @param int $uid User ID
     * @param string $username Username
     * @param string $email User email
     * @param string $class_name Class name
     * @param mixed $order_id Order ID
     * @param float $price Class price
     * @param string $date Registration date
     * @param string $lgl_fund_id LGL fund ID (deprecated - fund ID now determined internally by payment method)
     * @return void
     */
    private function processClassRegistration(
        int $uid,
        string $username,
        string $email,
        string $class_name,
        $order_id,
        float $price,
        string $date,
        string $lgl_fund_id
    ): void {
        // Retrieve current user LGL ID
        $user_lgl_id = get_user_meta($uid, 'lgl_id', true);
        $existing_user = $this->connection->getConstituentData($user_lgl_id);
        
        if (!$existing_user) {
            $this->helper->error('LGL ClassRegistrationAction: No existing LGL user found', [
                'user_id' => $uid,
                'username' => $username
            ]);
            $error_message = 'Unable to register for class. Your account was not found. Please contact support.';
            
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
            $this->helper->error('LGL ClassRegistrationAction: User ID not found', [
                'user_id' => $uid,
                'username' => $username
            ]);
            $error_message = 'Unable to register for class. User ID not found. Please contact support.';
            
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
            } else {
                throw new \RuntimeException($error_message);
            }
        }
        
        // Setup class payment (fund ID determined internally by payment method)
        $payment_data = $this->payments->setupClassPayment(
            $lgl_id,
            $order_id,
            $price,
            $date,
            $class_name,
            $class_name // event_name = class name for event tracking
        );
        
        if ($payment_data && isset($payment_data['success']) && $payment_data['success']) {
            // Payment already added by setupClassPayment() - just log the result
            $payment_id = $payment_data['id'] ?? null;
            
            if ($payment_id) {
                $this->helper->info('LGL ClassRegistrationAction: Class payment added', [
                    'payment_id' => $payment_id,
                    'lgl_id' => $lgl_id,
                    'order_id' => $order_id,
                    'class_name' => $class_name
                ]);
            } else {
                $this->helper->warning('LGL ClassRegistrationAction: Payment created but no ID returned', [
                    'lgl_id' => $lgl_id,
                    'order_id' => $order_id
                ]);
            }
        } else {
            $error = $payment_data['error'] ?? 'Unknown error';
            $this->helper->error('LGL ClassRegistrationAction: Failed to create payment', [
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
        return 'lgl_add_class_registration';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Register user for language class and process payment in LGL CRM';
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
                $this->helper->debug("LGL ClassRegistrationAction: Missing required field", ['field' => $field]);
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('LGL ClassRegistrationAction: Invalid user_id', ['user_id' => $request['user_id'] ?? null]);
            return false;
        }
        
        // Validate class_price is numeric
        if (isset($request['class_price']) && !is_numeric($request['class_price'])) {
            $this->helper->debug('LGL ClassRegistrationAction: Invalid class_price', ['class_price' => $request['class_price'] ?? null]);
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
            'class_name'
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
