<?php
/**
 * Renewal Strategy Manager
 * 
 * Determines which renewal system (WooCommerce Subscriptions or Plugin) manages each member.
 * Provides detection layer for dual renewal system support.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Memberships;

use UpstateInternational\LGL\LGL\Helper;

/**
 * RenewalStrategyManager Class
 * 
 * Detects and manages renewal strategies for members
 */
class RenewalStrategyManager {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Strategy constants
     */
    const STRATEGY_WOOCOMMERCE = 'woocommerce';
    const STRATEGY_PLUGIN = 'plugin';
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     */
    public function __construct(Helper $helper) {
        $this->helper = $helper;
    }
    
    /**
     * Determine which renewal strategy to use for a user
     * 
     * @param int $user_id User ID
     * @return string 'woocommerce' or 'plugin'
     */
    public function getRenewalStrategy(int $user_id): string {
        // Check if WC Subscriptions is active
        if (!$this->isWcSubscriptionsActive()) {
            $this->helper->debug("RenewalStrategy for user {$user_id}: plugin (WC Subscriptions not active)");
            return self::STRATEGY_PLUGIN;
        }
        
        // Check if user has active subscription
        if ($this->userHasActiveSubscription($user_id)) {
            $this->helper->debug("RenewalStrategy for user {$user_id}: woocommerce (active subscription found)");
            return self::STRATEGY_WOOCOMMERCE;
        }
        
        // User has no active subscription, use plugin system
        $this->helper->debug("RenewalStrategy for user {$user_id}: plugin (no active subscription)");
        return self::STRATEGY_PLUGIN;
    }
    
    /**
     * Check if WooCommerce Subscriptions plugin is active
     * 
     * @return bool
     */
    public function isWcSubscriptionsActive(): bool {
        return function_exists('wcs_get_subscriptions') && function_exists('wcs_get_users_subscriptions');
    }
    
    /**
     * Check if user has an active WooCommerce subscription
     * 
     * @param int $user_id User ID
     * @return bool
     */
    public function userHasActiveSubscription(int $user_id): bool {
        if (!$this->isWcSubscriptionsActive()) {
            return false;
        }
        
        try {
            $subscriptions = wcs_get_users_subscriptions($user_id);
            
            if (empty($subscriptions)) {
                return false;
            }
            
            // Check for active or pending subscriptions
            foreach ($subscriptions as $subscription) {
                if ($subscription->has_status(['active', 'pending', 'on-hold'])) {
                    $this->helper->debug("User {$user_id} has active subscription #{$subscription->get_id()}");
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->helper->debug("Error checking subscriptions for user {$user_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get subscription information for a user
     * 
     * @param int $user_id User ID
     * @return array|null Subscription data or null if none found
     */
    public function getUserSubscriptionInfo(int $user_id): ?array {
        if (!$this->isWcSubscriptionsActive()) {
            return null;
        }
        
        try {
            $subscriptions = wcs_get_users_subscriptions($user_id);
            
            if (empty($subscriptions)) {
                return null;
            }
            
            $active_subscriptions = [];
            foreach ($subscriptions as $subscription) {
                if ($subscription->has_status(['active', 'pending', 'on-hold'])) {
                    $active_subscriptions[] = [
                        'id' => $subscription->get_id(),
                        'status' => $subscription->get_status(),
                        'next_payment' => $subscription->get_date('next_payment'),
                        'requires_manual_renewal' => $subscription->get_requires_manual_renewal(),
                        'start_date' => $subscription->get_date('start'),
                        'total' => $subscription->get_total()
                    ];
                }
            }
            
            if (empty($active_subscriptions)) {
                return null;
            }
            
            return [
                'has_active_subscription' => true,
                'subscriptions' => $active_subscriptions,
                'strategy' => self::STRATEGY_WOOCOMMERCE
            ];
            
        } catch (\Exception $e) {
            $this->helper->debug("Error getting subscription info for user {$user_id}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get renewal statistics across all members
     * 
     * @return array Statistics
     */
    public function getRenewalStatistics(): array {
        $stats = [
            'total_members' => 0,
            'wc_managed' => 0,
            'plugin_managed' => 0,
            'wc_subscriptions_active' => $this->isWcSubscriptionsActive()
        ];
        
        // Get all members
        $members = get_users(['role__in' => ['ui_member', 'ui_patron_owner']]);
        $stats['total_members'] = count($members);
        
        foreach ($members as $member) {
            $strategy = $this->getRenewalStrategy($member->ID);
            if ($strategy === self::STRATEGY_WOOCOMMERCE) {
                $stats['wc_managed']++;
            } else {
                $stats['plugin_managed']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Get user's renewal status meta
     * 
     * @param int $user_id User ID
     * @return array Status information
     */
    public function getUserRenewalStatus(int $user_id): array {
        $strategy = $this->getRenewalStrategy($user_id);
        $subscription_status = get_user_meta($user_id, 'user-subscription-status', true);
        $renewal_date = get_user_meta($user_id, 'user-membership-renewal-date', true);
        
        return [
            'strategy' => $strategy,
            'subscription_status' => $subscription_status,
            'renewal_date' => $renewal_date ? date('Y-m-d', $renewal_date) : null,
            'renewal_timestamp' => $renewal_date,
            'has_active_wc_subscription' => $strategy === self::STRATEGY_WOOCOMMERCE
        ];
    }
    
    /**
     * Check if plugin should manage renewals for this user
     * 
     * @param int $user_id User ID
     * @return bool
     */
    public function shouldPluginManageRenewals(int $user_id): bool {
        return $this->getRenewalStrategy($user_id) === self::STRATEGY_PLUGIN;
    }
    
    /**
     * Check if WooCommerce should manage renewals for this user
     * 
     * @param int $user_id User ID
     * @return bool
     */
    public function shouldWooCommerceManageRenewals(int $user_id): bool {
        return $this->getRenewalStrategy($user_id) === self::STRATEGY_WOOCOMMERCE;
    }
}

