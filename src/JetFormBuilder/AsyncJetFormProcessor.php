<?php
/**
 * Async JetForm Processor
 * 
 * Handles asynchronous LGL API processing for all JetFormBuilder actions.
 * Processes LGL sync in background to speed up form submission.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Constituents;

/**
 * AsyncJetFormProcessor Class
 * 
 * Handles background processing of LGL API calls for all JetFormBuilder actions
 */
class AsyncJetFormProcessor {
    
    /**
     * Cron hook name for async processing
     */
    const CRON_HOOK = 'lgl_process_jetform_async';
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Connection service
     * 
     * @var Connection
     */
    private Connection $connection;
    
    /**
     * Constituents service
     * 
     * @var Constituents
     */
    private Constituents $constituents;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param Connection $connection LGL connection service
     * @param Constituents $constituents LGL constituents service
     */
    public function __construct(
        Helper $helper,
        Connection $connection,
        Constituents $constituents
    ) {
        $this->helper = $helper;
        $this->connection = $connection;
        $this->constituents = $constituents;
        
        // Register WP Cron handler
        add_action(self::CRON_HOOK, [$this, 'handleAsyncRequest'], 10, 2); // 2 args: user_id, action_type
    }
    
    /**
     * Schedule async LGL processing for a JetForm action
     * 
     * Uses WP Cron to process LGL sync in background
     * 
     * @param string $action_type Action type (user_edit, user_registration, membership_update, etc.)
     * @param int $user_id WordPress user ID
     * @param array $context Context data for processing
     * @return void
     */
    public function scheduleAsyncProcessing(string $action_type, int $user_id, array $context): void {
        $this->helper->debug('⏰ AsyncJetFormProcessor: Scheduling async processing (WP Cron)', [
            'action_type' => $action_type,
            'user_id' => $user_id
        ]);
        
        // Clear any previous processed flag for this action type to allow new updates
        // The flag should only prevent duplicate processing of the SAME request, not block new requests
        $processed_key = '_lgl_async_processed_' . $action_type;
        delete_user_meta($user_id, $processed_key);
        delete_user_meta($user_id, $processed_key . '_at');
        
        // Store context data in user meta for async processing
        update_user_meta($user_id, '_lgl_async_queued', true);
        update_user_meta($user_id, '_lgl_async_queued_at', current_time('mysql'));
        update_user_meta($user_id, '_lgl_async_action_type', $action_type);
        update_user_meta($user_id, '_lgl_async_context', $context);
        
        // Schedule WP Cron event to run immediately (on next page load)
        $scheduled = wp_schedule_single_event(time(), self::CRON_HOOK, [$user_id, $action_type]);
        
        if ($scheduled === false) {
            // Check if already scheduled (prevents duplicates)
            $next_scheduled = wp_next_scheduled(self::CRON_HOOK, [$user_id, $action_type]);
            if ($next_scheduled) {
                $this->helper->debug('⚠️ AsyncJetFormProcessor: Cron already scheduled', [
                    'user_id' => $user_id,
                    'action_type' => $action_type,
                    'next_run' => date('Y-m-d H:i:s', $next_scheduled)
                ]);
            } else {
                $this->helper->debug('❌ AsyncJetFormProcessor: Failed to schedule cron', [
                    'user_id' => $user_id,
                    'action_type' => $action_type
                ]);
            }
        } else {
            $this->helper->debug('✅ AsyncJetFormProcessor: WP Cron event scheduled', [
                'user_id' => $user_id,
                'action_type' => $action_type,
                'hook' => self::CRON_HOOK,
                'scheduled_for' => date('Y-m-d H:i:s', time())
            ]);
            
            // Trigger cron immediately (non-blocking) to ensure it runs
            // This uses WordPress's spawn_cron() which makes a non-blocking HTTP request
            if (!defined('DISABLE_WP_CRON') || !constant('DISABLE_WP_CRON')) {
                spawn_cron();
                $this->helper->debug('🚀 AsyncJetFormProcessor: Triggered cron spawn for immediate execution', [
                    'user_id' => $user_id,
                    'action_type' => $action_type
                ]);
            }
        }
    }
    
    /**
     * Handle async request (called by WP Cron)
     * 
     * @param int $user_id WordPress user ID (passed by WP Cron)
     * @param string $action_type Action type (passed by WP Cron)
     * @return void
     */
    public function handleAsyncRequest(int $user_id, string $action_type): void {
        $this->helper->debug('🔄 AsyncJetFormProcessor: Processing async request (WP Cron)', [
            'user_id' => $user_id,
            'action_type' => $action_type,
            'timestamp' => current_time('mysql'),
            'cron_hook' => self::CRON_HOOK
        ]);
        
        if (!$user_id || $user_id <= 0) {
            $this->helper->debug('❌ AsyncJetFormProcessor: Invalid user ID', [
                'user_id' => $user_id
            ]);
            return;
        }
        
        // Check if already processed (prevent duplicate processing)
        $processed_key = '_lgl_async_processed_' . $action_type;
        $already_processed = get_user_meta($user_id, $processed_key, true);
        if ($already_processed) {
            $this->helper->debug('⏭️ AsyncJetFormProcessor: Already processed, skipping', [
                'user_id' => $user_id,
                'action_type' => $action_type,
                'processed_at' => get_user_meta($user_id, $processed_key . '_at', true)
            ]);
            return;
        }
        
        // Get stored context data
        $context = get_user_meta($user_id, '_lgl_async_context', true);
        $stored_action_type = get_user_meta($user_id, '_lgl_async_action_type', true);
        
        if (empty($context) || $stored_action_type !== $action_type) {
            $this->helper->debug('❌ AsyncJetFormProcessor: Missing or mismatched context data', [
                'user_id' => $user_id,
                'action_type' => $action_type,
                'stored_action_type' => $stored_action_type,
                'has_context' => !empty($context)
            ]);
            return;
        }
        
        try {
            // Process based on action type
            switch ($action_type) {
                case 'user_edit':
                    $this->processUserEdit($user_id, $context);
                    break;
                    
                case 'user_registration':
                    $this->processUserRegistration($user_id, $context);
                    break;
                    
                case 'membership_update':
                    $this->processMembershipUpdate($user_id, $context);
                    break;
                    
                case 'membership_renewal':
                    $this->processMembershipRenewal($user_id, $context);
                    break;
                    
                case 'membership_deactivation':
                    $this->processMembershipDeactivation($user_id, $context);
                    break;
                    
                case 'class_registration':
                    $this->processClassRegistration($user_id, $context);
                    break;
                    
                case 'event_registration':
                    $this->processEventRegistration($user_id, $context);
                    break;
                    
                default:
                    throw new \Exception("Unknown action type: {$action_type}");
            }
            
            // Mark as processed
            update_user_meta($user_id, $processed_key, true);
            update_user_meta($user_id, $processed_key . '_at', current_time('mysql'));
            
            // Clean up queued meta
            delete_user_meta($user_id, '_lgl_async_queued');
            delete_user_meta($user_id, '_lgl_async_context');
            delete_user_meta($user_id, '_lgl_async_action_type');
            
            $this->helper->debug('✅ AsyncJetFormProcessor: Async processing completed', [
                'user_id' => $user_id,
                'action_type' => $action_type
            ]);
            
        } catch (\Exception $e) {
            $this->helper->debug('❌ AsyncJetFormProcessor: Async processing failed', [
                'user_id' => $user_id,
                'action_type' => $action_type,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Mark as failed for retry
            update_user_meta($user_id, '_lgl_async_failed_' . $action_type, true);
            update_user_meta($user_id, '_lgl_async_error_' . $action_type, $e->getMessage());
            update_user_meta($user_id, '_lgl_async_failed_at_' . $action_type, current_time('mysql'));
            
            // Re-schedule for retry (in 5 minutes)
            wp_schedule_single_event(time() + (5 * MINUTE_IN_SECONDS), self::CRON_HOOK, [$user_id, $action_type]);
            
            $this->helper->debug('🔄 AsyncJetFormProcessor: Re-scheduled for retry', [
                'user_id' => $user_id,
                'action_type' => $action_type,
                'retry_in' => '5 minutes'
            ]);
        }
    }
    
    /**
     * Process user edit action
     * 
     * @param int $user_id WordPress user ID
     * @param array $context Context data
     * @return void
     */
    private function processUserEdit(int $user_id, array $context): void {
        $request = $context['request'] ?? [];
        $skip_membership = $context['skip_membership'] ?? true;
        
        $this->helper->debug('🔄 AsyncJetFormProcessor: Processing user edit', [
            'user_id' => $user_id,
            'skip_membership' => $skip_membership
        ]);
        
        // IMPORTANT: JetForm's "Update User" action runs BEFORE this and updates WordPress
        // user object fields (first_name, last_name, user_email). Our setData() method now
        // prioritizes WordPress user object fields over custom meta fields, so we don't need
        // to sync or overwrite anything - we just read from what JetForm already saved.
        
        $user = get_user_by('id', $user_id);
        if (!$user) {
            throw new \Exception("User not found: {$user_id}");
        }
        
        // Log what we're reading (for debugging)
        // setData() will read from WordPress user object fields first, then fall back to meta
        $this->helper->debug('📋 AsyncJetFormProcessor: Reading from WordPress user object (JetForm updated these)', [
            'user_id' => $user_id,
            'wp_first_name' => $user->first_name,
            'wp_last_name' => $user->last_name,
            'wp_email' => $user->user_email,
            'form_firstname' => $request['user_firstname'] ?? 'not provided',
            'form_lastname' => $request['user_lastname'] ?? 'not provided',
            'form_email' => $request['user_email'] ?? 'not provided',
            'note' => 'setData() now prioritizes WordPress user object fields over custom meta'
        ]);
        
        // Update constituent using Constituents service (reads from current user meta)
        // Pass empty array to avoid merging stale form request data
        $result = $this->constituents->setDataAndUpdate($user_id, [], $skip_membership);
        
        if ($result && isset($result['success']) && $result['success']) {
            $this->helper->debug('✅ AsyncJetFormProcessor: User edit completed', [
                'user_id' => $user_id
            ]);
        } else {
            $error = $result['error'] ?? 'Unknown error';
            throw new \Exception("User edit failed: {$error}");
        }
    }
    
    /**
     * Process user registration action
     * 
     * @param int $user_id WordPress user ID
     * @param array $context Context data
     * @return void
     */
    private function processUserRegistration(int $user_id, array $context): void {
        $request = $context['request'] ?? [];
        $username = $context['username'] ?? '';
        $emails = $context['emails'] ?? [];
        $skip_payment = $context['skip_payment'] ?? false;
        
        $this->helper->debug('🔄 AsyncJetFormProcessor: Processing user registration', [
            'user_id' => $user_id
        ]);
        
        // Search for existing LGL contact
        $match = $this->connection->searchByName($username, $emails);
        $lgl_id = $match['id'] ?? null;
        
        if (!$lgl_id) {
            // Create new constituent using Constituents service (handles everything)
            $this->constituents->setData($user_id);
            $response = $this->constituents->createConstituent();
            
            if (!$response['success'] || empty($response['data']['id'])) {
                throw new \Exception('Failed to create constituent');
            }
            
            $lgl_id = (string) $response['data']['id'];
            update_user_meta($user_id, 'lgl_id', $lgl_id);
            
            // Add email, phone, address
            $this->addConstituentDetails($lgl_id, $user_id);
        } else {
            // Update existing constituent using Constituents service (handles everything)
            update_user_meta($user_id, 'lgl_id', $lgl_id);
            $this->constituents->setData($user_id);
            $this->constituents->updateConstituent($lgl_id);
        }
        
        // Add payment if not skipped (for paid registrations, not family members)
        if (!$skip_payment) {
            $order_id = $request['order_id'] ?? $request['inserted_post_id'] ?? null;
            $price = (float) ($request['price'] ?? 0);
            
            if ($order_id && $price > 0) {
                $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
                if ($container->has('lgl.payments')) {
                    $payments = $container->get('lgl.payments');
                    $payment_type = $request['payment_method'] ?? $request['payment_type'] ?? 'online';
                    $payments->setupMembershipPayment($lgl_id, $order_id, $price, date('Y-m-d'), $payment_type);
                }
            }
        }
        
        $this->helper->debug('✅ AsyncJetFormProcessor: User registration completed', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id
        ]);
    }
    
    /**
     * Process membership update action
     * 
     * @param int $user_id WordPress user ID
     * @param array $context Context data
     * @return void
     */
    private function processMembershipUpdate(int $user_id, array $context): void {
        $lgl_id = $context['lgl_id'] ?? null;
        $order_id = $context['order_id'] ?? null;
        $price = $context['price'] ?? 0;
        $date = $context['date'] ?? date('Y-m-d');
        $request = $context['request'] ?? [];
        
        if (!$lgl_id) {
            throw new \Exception('LGL ID not found in context');
        }
        
        $this->helper->debug('🔄 AsyncJetFormProcessor: Processing membership update', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id
        ]);
        
        // Get existing user data
        $user = $this->connection->getConstituentData($lgl_id);
        if (!$user) {
            throw new \Exception('User data not found in LGL');
        }
        
        // Deactivate existing memberships
        $this->deactivateExistingMemberships($user, $lgl_id);
        
        // Add new membership
        $this->constituents->setMembership($user_id);
        $membership_data = $this->constituents->getMembershipData();
        $result = $this->connection->addMembership($lgl_id, $membership_data);
        
        if (empty($result['success'])) {
            throw new \Exception('Failed to add membership');
        }
        
        // Add payment
        if ($order_id && $price > 0) {
            $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
            if ($container->has('lgl.payments')) {
                $payments = $container->get('lgl.payments');
                $payments->setupMembershipPayment(null, $order_id, $price, $date);
            }
        }
        
        $this->helper->debug('✅ AsyncJetFormProcessor: Membership update completed', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id
        ]);
    }
    
    /**
     * Process membership renewal action
     * 
     * @param int $user_id WordPress user ID
     * @param array $context Context data
     * @return void
     */
    private function processMembershipRenewal(int $user_id, array $context): void {
        $lgl_id = $context['lgl_id'] ?? null;
        $order_id = $context['order_id'] ?? null;
        $price = $context['price'] ?? 0;
        $date = $context['date'] ?? date('Y-m-d');
        
        if (!$lgl_id) {
            throw new \Exception('LGL ID not found in context');
        }
        
        $this->helper->debug('🔄 AsyncJetFormProcessor: Processing membership renewal', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id
        ]);
        
        // Add renewal membership
        $this->constituents->setMembership($user_id);
        $membership_data = $this->constituents->getMembershipData();
        $result = $this->connection->addMembership($lgl_id, $membership_data);
        
        if (empty($result['success'])) {
            throw new \Exception('Failed to add renewal membership');
        }
        
        // Add renewal payment
        if ($order_id && $price > 0) {
            $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
            if ($container->has('lgl.payments')) {
                $payments = $container->get('lgl.payments');
                $payments->setupMembershipPayment(null, $order_id, $price, $date);
            }
        }
        
        $this->helper->debug('✅ AsyncJetFormProcessor: Membership renewal completed', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id
        ]);
    }
    
    /**
     * Process membership deactivation action
     * 
     * @param int $user_id WordPress user ID
     * @param array $context Context data
     * @return void
     */
    private function processMembershipDeactivation(int $user_id, array $context): void {
        $lgl_id = $context['lgl_id'] ?? null;
        
        if (!$lgl_id) {
            throw new \Exception('LGL ID not found in context');
        }
        
        $this->helper->debug('🔄 AsyncJetFormProcessor: Processing membership deactivation', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id
        ]);
        
        // Get existing user data
        $user = $this->connection->getConstituentData($lgl_id);
        if (!$user) {
            throw new \Exception('User data not found in LGL');
        }
        
        // Deactivate existing memberships
        $this->deactivateExistingMemberships($user, $lgl_id);
        
        $this->helper->debug('✅ AsyncJetFormProcessor: Membership deactivation completed', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id
        ]);
    }
    
    /**
     * Process class registration action
     * 
     * @param int $user_id WordPress user ID
     * @param array $context Context data
     * @return void
     */
    private function processClassRegistration(int $user_id, array $context): void {
        $lgl_id = $context['lgl_id'] ?? null;
        $class_name = $context['class_name'] ?? '';
        $order_id = $context['order_id'] ?? null;
        $price = $context['price'] ?? 0;
        $date = $context['date'] ?? date('Y-m-d');
        
        if (!$lgl_id) {
            throw new \Exception('LGL ID not found in context');
        }
        
        $this->helper->debug('🔄 AsyncJetFormProcessor: Processing class registration', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id,
            'class_name' => $class_name
        ]);
        
        // Setup class payment (fund ID determined internally by payment method)
        $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
        if ($container->has('lgl.payments')) {
            $payments = $container->get('lgl.payments');
            $payment_data = $payments->setupClassPayment(
                $lgl_id,
                $order_id,
                $price,
                $date,
                $class_name,
                $class_name // event_name = class name for event tracking
            );
            
            if ($payment_data && isset($payment_data['success']) && $payment_data['success']) {
                $payment_id = $payment_data['id'] ?? null;
                $this->helper->debug('✅ AsyncJetFormProcessor: Class registration completed', [
                    'user_id' => $user_id,
                    'lgl_id' => $lgl_id,
                    'payment_id' => $payment_id
                ]);
            } else {
                $error = $payment_data['error'] ?? 'Unknown error';
                throw new \Exception("Class registration failed: {$error}");
            }
        } else {
            throw new \Exception('Payments service not available');
        }
    }
    
    /**
     * Process event registration action
     * 
     * @param int $user_id WordPress user ID
     * @param array $context Context data
     * @return void
     */
    private function processEventRegistration(int $user_id, array $context): void {
        $lgl_id = $context['lgl_id'] ?? null;
        $event_name = $context['event_name'] ?? '';
        $order_id = $context['order_id'] ?? null;
        $price = $context['price'] ?? 0;
        $date = $context['date'] ?? date('Y-m-d');
        
        if (!$lgl_id) {
            throw new \Exception('LGL ID not found in context');
        }
        
        $this->helper->debug('🔄 AsyncJetFormProcessor: Processing event registration', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id,
            'event_name' => $event_name
        ]);
        
        // Setup event payment (fund ID determined internally by payment method)
        $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
        if ($container->has('lgl.payments')) {
            $payments = $container->get('lgl.payments');
            $payment_data = $payments->setupEventPayment(
                $lgl_id,
                $order_id,
                $price,
                $date,
                $event_name
            );
            
            if ($payment_data && isset($payment_data['success']) && $payment_data['success']) {
                $payment_id = $payment_data['id'] ?? null;
                $this->helper->debug('✅ AsyncJetFormProcessor: Event registration completed', [
                    'user_id' => $user_id,
                    'lgl_id' => $lgl_id,
                    'payment_id' => $payment_id
                ]);
            } else {
                $error = $payment_data['error'] ?? 'Unknown error';
                throw new \Exception("Event registration failed: {$error}");
            }
        } else {
            throw new \Exception('Payments service not available');
        }
    }
    
    /**
     * Add constituent details (email, phone, address)
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $user_id WordPress user ID
     * @return void
     */
    private function addConstituentDetails(string $lgl_id, int $user_id): void {
        // Add email
        $email_data = $this->constituents->getEmailData();
        if (!empty($email_data)) {
            foreach ($email_data as $email) {
                $this->connection->addEmailAddress($lgl_id, $email);
            }
        }
        
        // Add phone
        $phone_data = $this->constituents->getPhoneData();
        if (!empty($phone_data)) {
            foreach ($phone_data as $phone) {
                $this->connection->addPhoneNumber($lgl_id, $phone);
            }
        }
        
        // Add address
        $address_data = $this->constituents->getAddressData();
        if (!empty($address_data)) {
            foreach ($address_data as $address) {
                $this->connection->addStreetAddressSafe($lgl_id, $address);
            }
        }
    }
    
    /**
     * Deactivate existing memberships
     * 
     * @param mixed $user User data from LGL
     * @param string $lgl_id LGL constituent ID
     * @return void
     */
    private function deactivateExistingMemberships($user, string $lgl_id): void {
        $memberships = is_object($user) ? ($user->memberships ?? []) : ($user['memberships'] ?? []);
        
        foreach ($memberships as $membership) {
            $membership_id = is_object($membership) ? ($membership->id ?? null) : ($membership['id'] ?? null);
            $finish_date = is_object($membership) ? ($membership->finish_date ?? null) : ($membership['finish_date'] ?? null);
            
            if ($membership_id && $finish_date && strtotime($finish_date) >= strtotime(date('Y-m-d'))) {
                // Deactivate active membership
                $deactivation_data = [
                    'finish_date' => date('Y-m-d'),
                    'note' => 'Membership ended via form on ' . date('Y-m-d')
                ];
                
                $this->connection->updateMembership((string) $membership_id, $deactivation_data);
            }
        }
    }
}

