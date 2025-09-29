<?php
/**
 * Subscription Handler
 * 
 * Handles WooCommerce subscription status changes and updates user memberships.
 * Manages subscription cancellations and family member deactivations.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;

/**
 * SubscriptionHandler Class
 * 
 * Processes WooCommerce subscription status changes
 */
class SubscriptionHandler {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * LGL WP Users service
     * 
     * @var WpUsers
     */
    private WpUsers $wpUsers;
    
    /**
     * Cancellation statuses that trigger deactivation
     * 
     * @var array<string>
     */
    private array $cancellationStatuses = [
        'cancelled',
        'pending-cancel'
    ];
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param WpUsers $wpUsers LGL WP Users service
     */
    public function __construct(Helper $helper, WpUsers $wpUsers) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
    }
    
    /**
     * Handle subscription status update
     * 
     * @param int $subscription_id Subscription ID
     * @param string $old_status Previous subscription status
     * @param string $new_status New subscription status
     * @return void
     */
    public function handleStatusUpdate(int $subscription_id, string $old_status, string $new_status): void {
        if (!function_exists('wcs_get_subscription')) {
            $this->helper->debug('SubscriptionHandler: WooCommerce Subscriptions not available');
            return;
        }
        
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            $this->helper->debug('SubscriptionHandler: Subscription not found', $subscription_id);
            return;
        }
        
        $this->helper->debug('SubscriptionHandler: Processing status update', [
            'subscription_id' => $subscription_id,
            'old_status' => $old_status,
            'new_status' => $new_status
        ]);
        
        // Check if subscription is being cancelled
        if ($this->isCancellationStatus($new_status)) {
            $this->processCancellation($subscription);
        }
    }
    
    /**
     * Handle subscription cancellation
     * 
     * @param int $subscription_id Subscription ID
     * @return void
     */
    public function handleCancellation(int $subscription_id): void {
        if (!function_exists('wcs_get_subscription')) {
            $this->helper->debug('SubscriptionHandler: WooCommerce Subscriptions not available');
            return;
        }
        
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            $this->helper->debug('SubscriptionHandler: Subscription not found', $subscription_id);
            return;
        }
        
        $user_id = $subscription->get_customer_id();
        $this->helper->debug('SubscriptionHandler: Cancelling subscription for user', $user_id);
        
        $this->processCancellation($subscription);
    }
    
    /**
     * Process subscription cancellation
     * 
     * @param \WC_Subscription $subscription WooCommerce subscription
     * @return void
     */
    private function processCancellation(\WC_Subscription $subscription): void {
        $user_id = $subscription->get_customer_id();
        
        if (!$user_id) {
            $this->helper->debug('SubscriptionHandler: No user ID found for subscription');
            return;
        }
        
        // Deactivate user membership
        $this->deactivateUserMembership($user_id);
        
        // Update user meta
        $this->updateUserMetaOnCancellation($user_id);
        
        // Handle family members if user is a patron owner
        $this->handleFamilyMemberCancellation($user_id);
    }
    
    /**
     * Deactivate user membership in LGL
     * 
     * @param int $user_id User ID
     * @return void
     */
    private function deactivateUserMembership(int $user_id): void {
        // Use legacy LGL_API for now - this will be modernized in future phases
        if (class_exists('LGL_API')) {
            $lgl_api = \LGL_API::get_instance();
            $request = ['user_id' => $user_id];
            $lgl_api->lgl_deactivate_membership($request, null);
            $this->helper->debug('SubscriptionHandler: Deactivated membership in LGL', $user_id);
        } else {
            $this->helper->debug('SubscriptionHandler: LGL_API not available for membership deactivation');
        }
    }
    
    /**
     * Update user meta on subscription cancellation
     * 
     * @param int $user_id User ID
     * @return void
     */
    private function updateUserMetaOnCancellation(int $user_id): void {
        update_user_meta($user_id, 'user-subscription-status', 'cancelled');
        update_user_meta($user_id, 'user-membership-start-date', '');
        update_user_meta($user_id, 'user-membership-renewal-date', '');
        update_user_meta($user_id, 'user-subscription-id', '');
        
        $this->helper->debug('SubscriptionHandler: Updated user meta for cancellation', $user_id);
    }
    
    /**
     * Handle family member cancellation
     * 
     * @param int $parent_user_id Parent user ID
     * @return void
     */
    private function handleFamilyMemberCancellation(int $parent_user_id): void {
        $current_role = $this->wpUsers->uiGetUserRole($parent_user_id);
        
        if ($current_role !== 'ui_patron_owner') {
            return; // Not a family membership
        }
        
        $child_relations = $this->wpUsers->uiGetChildRelations($parent_user_id);
        
        $this->helper->debug('SubscriptionHandler: Processing family member cancellation', [
            'parent_id' => $parent_user_id,
            'children' => $child_relations
        ]);
        
        foreach ($child_relations as $child_id) {
            $this->helper->debug('SubscriptionHandler: Updating meta for child', $child_id);
            
            // Update child user meta
            $this->updateUserMetaOnCancellation($child_id);
            
            // Deactivate child membership
            $this->deactivateUserMembership($child_id);
        }
    }
    
    /**
     * Check if status is a cancellation status
     * 
     * @param string $status Subscription status
     * @return bool
     */
    private function isCancellationStatus(string $status): bool {
        return in_array($status, $this->cancellationStatuses);
    }
    
    /**
     * Get subscription information
     * 
     * @param int $subscription_id Subscription ID
     * @return array|null Subscription data or null if not found
     */
    public function getSubscriptionInfo(int $subscription_id): ?array {
        if (!function_exists('wcs_get_subscription')) {
            return null;
        }
        
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            return null;
        }
        
        return [
            'id' => $subscription->get_id(),
            'status' => $subscription->get_status(),
            'customer_id' => $subscription->get_customer_id(),
            'start_date' => $subscription->get_date('start'),
            'next_payment' => $subscription->get_date('next_payment'),
            'end_date' => $subscription->get_date('end'),
            'total' => $subscription->get_total(),
            'billing_period' => $subscription->get_billing_period(),
            'billing_interval' => $subscription->get_billing_interval(),
        ];
    }
    
    /**
     * Get user subscription status
     * 
     * @param int $user_id User ID
     * @return array User subscription information
     */
    public function getUserSubscriptionStatus(int $user_id): array {
        return [
            'subscription_status' => get_user_meta($user_id, 'user-subscription-status', true),
            'subscription_id' => get_user_meta($user_id, 'user-subscription-id', true),
            'membership_start_date' => get_user_meta($user_id, 'user-membership-start-date', true),
            'membership_renewal_date' => get_user_meta($user_id, 'user-membership-renewal-date', true),
            'membership_type' => get_user_meta($user_id, 'user-membership-type', true),
        ];
    }
    
    /**
     * Add cancellation status
     * 
     * @param string $status Status to add
     * @return void
     */
    public function addCancellationStatus(string $status): void {
        if (!in_array($status, $this->cancellationStatuses)) {
            $this->cancellationStatuses[] = $status;
            $this->helper->debug('SubscriptionHandler: Added cancellation status', $status);
        }
    }
    
    /**
     * Remove cancellation status
     * 
     * @param string $status Status to remove
     * @return void
     */
    public function removeCancellationStatus(string $status): void {
        $key = array_search($status, $this->cancellationStatuses);
        if ($key !== false) {
            unset($this->cancellationStatuses[$key]);
            $this->cancellationStatuses = array_values($this->cancellationStatuses); // Re-index
            $this->helper->debug('SubscriptionHandler: Removed cancellation status', $status);
        }
    }
    
    /**
     * Get handler status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'woocommerce_available' => class_exists('WC_Order'),
            'subscriptions_available' => function_exists('wcs_get_subscription'),
            'lgl_api_available' => class_exists('LGL_API'),
            'cancellation_statuses' => $this->cancellationStatuses,
            'dependencies_met' => class_exists('WC_Order') && function_exists('wcs_get_subscription')
        ];
    }
    
    /**
     * Get cancellation statuses
     * 
     * @return array<string>
     */
    public function getCancellationStatuses(): array {
        return $this->cancellationStatuses;
    }
    
    /**
     * Validate subscription for processing
     * 
     * @param int $subscription_id Subscription ID
     * @return array Validation result
     */
    public function validateSubscription(int $subscription_id): array {
        $result = [
            'valid' => false,
            'errors' => [],
            'subscription_id' => $subscription_id
        ];
        
        if (!function_exists('wcs_get_subscription')) {
            $result['errors'][] = 'WooCommerce Subscriptions not available';
            return $result;
        }
        
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            $result['errors'][] = 'Subscription not found';
            return $result;
        }
        
        $user_id = $subscription->get_customer_id();
        if (!$user_id) {
            $result['errors'][] = 'No customer associated with subscription';
            return $result;
        }
        
        $result['valid'] = true;
        $result['user_id'] = $user_id;
        $result['status'] = $subscription->get_status();
        
        return $result;
    }
}
