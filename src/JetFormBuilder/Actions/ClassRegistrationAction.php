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
     * Handle class registration action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
        // Validate request data
        if (!$this->validateRequest($request)) {
            $this->helper->debug('ClassRegistrationAction: Invalid request data', $request);
                $error_message = 'Invalid request data. Please check that all required fields are filled correctly.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        $this->helper->debug('ClassRegistrationAction: Processing request', $request);
        
        $uid = (int) $request['user_id'];
        $class_id = $request['class_id'] ?? null;
        $username = str_replace(' ', '%20', $request['username']);
        $class_name = $request['class_name'] ?? '';
        $order_id = $request['inserted_post_id'] ?? null;
        $price = (float) ($request['class_price'] ?? 0);
        $date = date('Y-m-d', time());
        $lgl_fund_id = $request['lgl_fund_id'] ?? '';
        
        if ($uid === 0 || empty($username)) {
            $this->helper->debug('No User ID or username in Request, ClassRegistrationAction');
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
            $this->helper->debug('ClassRegistrationAction: User not found', $uid);
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
        
        // Process class registration
        $this->processClassRegistration($uid, $username, $email, $class_name, $order_id, $price, $date, $lgl_fund_id);
            
        } catch (\Exception $e) {
            $this->helper->debug('ClassRegistrationAction: Error occurred', [
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
            $this->helper->debug('ClassRegistrationAction: No existing LGL user found for class registration', $username);
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
        $this->helper->debug('LGL USER EXISTS: ', $lgl_id);
        
        if (!$lgl_id) {
            $this->helper->debug('Cannot find user with name, ClassRegistrationAction', $username);
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
        
        $this->helper->debug('Class payment data: ', $payment_data);
        
        if ($payment_data && isset($payment_data['success']) && $payment_data['success']) {
            // Payment already added by setupClassPayment() - just log the result
            $payment_id = $payment_data['id'] ?? null;
            
            if ($payment_id) {
                $this->helper->debug('Class Payment ID: ', $payment_id);
            } else {
                $this->helper->debug('ClassRegistrationAction: Payment created but no ID returned', $payment_data);
            }
        } else {
            $error = $payment_data['error'] ?? 'Unknown error';
            $this->helper->debug('ClassRegistrationAction: Failed to create payment', $error);
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
                $this->helper->debug("ClassRegistrationAction: Missing required field: {$field}");
                return false;
            }
        }
        
        // Validate user_id is numeric and positive
        if (!isset($request['user_id']) || !is_numeric($request['user_id']) || (int)$request['user_id'] <= 0) {
            $this->helper->debug('ClassRegistrationAction: Invalid user_id');
            return false;
        }
        
        // Validate class_price is numeric
        if (isset($request['class_price']) && !is_numeric($request['class_price'])) {
            $this->helper->debug('ClassRegistrationAction: Invalid class_price');
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
}
