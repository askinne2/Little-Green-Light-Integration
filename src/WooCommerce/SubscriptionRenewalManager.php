<?php
/**
 * WooCommerce Subscription Renewal Manager
 * 
 * Handles subscription renewal settings and management.
 * Moved from theme to plugin for proper separation of concerns.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

/**
 * Subscription Renewal Manager Class
 * 
 * Manages subscription renewal settings with proper error handling and logging
 */
class SubscriptionRenewalManager {
    
    /**
     * Option key for tracking renewal updates
     */
    const UPDATE_OPTION_KEY = 'lgl_subscription_renewal_update_done';
    
    /**
     * Initialize subscription renewal management
     */
    public static function init(): void {
        // Register shortcodes
        add_shortcode('lgl_run_subscription_renewal', [static::class, 'renewalShortcode']);
        add_shortcode('lgl_display_manual_renewal_status', [static::class, 'displayManualRenewalShortcode']);
        
        // Legacy shortcode support (will be deprecated)
        add_shortcode('run_subscription_renewal', [static::class, 'legacyRenewalShortcode']);
        add_shortcode('display_requires_manual_renewal', [static::class, 'legacyDisplayShortcode']);
        
        error_log('LGL Subscription Renewal Manager: Initialized successfully');
    }
    
    /**
     * Run subscription renewal change (one-time operation)
     * 
     * @return string Status message
     */
    public static function runSubscriptionRenewalChangeOnce(): string {
        // Check if WooCommerce Subscriptions is active
        if (!function_exists('wcs_get_subscriptions')) {
            error_log('LGL Subscription Renewal: WooCommerce Subscriptions not active');
            return 'Error: WooCommerce Subscriptions plugin is required.';
        }
        
        try {
            error_log('LGL Subscription Renewal: Starting renewal update process');
            
            // Check if the function has already run
            if (get_option(static::UPDATE_OPTION_KEY)) {
                error_log('LGL Subscription Renewal: Update already completed');
                return 'Subscription renewal update already completed.';
            }
            
            // Get all active subscriptions
            $subscriptions = wcs_get_subscriptions(['subscription_status' => 'active']);
            $updated_count = 0;
            
            error_log('LGL Subscription Renewal: Found ' . count($subscriptions) . ' active subscriptions');
            
            foreach ($subscriptions as $subscription) {
                $subscription_id = $subscription->get_id();
                
                try {
                    // Update the subscription to require manual renewals
                    $subscription->set_requires_manual_renewal(true);
                    $subscription->save();
                    
                    $updated_count++;
                    
                    // Log the update
                    $requires_manual_renewal = $subscription->get_requires_manual_renewal();
                    error_log(sprintf(
                        'LGL Subscription Renewal: Updated subscription #%d - Manual renewal: %s',
                        $subscription_id,
                        $requires_manual_renewal ? 'true' : 'false'
                    ));
                    
                } catch (\Exception $e) {
                    error_log('LGL Subscription Renewal: Error updating subscription #' . $subscription_id . ': ' . $e->getMessage());
                }
            }
            
            // Set the flag to indicate the function has been executed
            update_option(static::UPDATE_OPTION_KEY, true);
            
            $message = sprintf(
                'Subscription renewal update completed. Updated %d of %d subscriptions.',
                $updated_count,
                count($subscriptions)
            );
            
            error_log('LGL Subscription Renewal: ' . $message);
            return $message;
            
        } catch (\Exception $e) {
            error_log('LGL Subscription Renewal Error: ' . $e->getMessage());
            return 'Error occurred during subscription renewal update. Please check logs.';
        }
    }
    
    /**
     * Display manual renewal status for current user
     * 
     * @return string HTML output
     */
    public static function displayManualRenewalStatus(): string {
        // Check if WooCommerce Subscriptions is active
        if (!function_exists('wcs_get_users_subscriptions')) {
            return '<div class="notice notice-error"><p>WooCommerce Subscriptions plugin is required.</p></div>';
        }
        
        // Get the current user ID
        $user_id = get_current_user_id();
        if (!$user_id) {
            return '<div class="notice notice-warning"><p>Please log in to view your subscription status.</p></div>';
        }
        
        try {
            // Get the subscriptions for the current user
            $subscriptions = wcs_get_users_subscriptions($user_id);
            if (empty($subscriptions)) {
                return '<div class="notice notice-info"><p>No subscriptions found for your account.</p></div>';
            }
            
            ob_start();
            ?>
            <div class="lgl-subscription-status">
                <style>
                    .lgl-subscription-status { font-family: Arial, sans-serif; }
                    .lgl-subscription-item { 
                        background: #f9f9f9; 
                        border-left: 4px solid #00797A; 
                        padding: 15px; 
                        margin-bottom: 15px; 
                        border-radius: 5px;
                    }
                    .lgl-subscription-item h4 { margin-top: 0; color: #00797A; }
                    .lgl-auto-renew-status { 
                        display: inline-block; 
                        padding: 5px 10px; 
                        border-radius: 3px; 
                        font-weight: bold; 
                        font-size: 14px;
                    }
                    .lgl-auto-renew-on { background-color: #d4edda; color: #155724; }
                    .lgl-auto-renew-off { background-color: #f8d7da; color: #721c24; }
                    .lgl-manage-link { 
                        display: inline-block; 
                        margin-top: 10px; 
                        padding: 8px 15px; 
                        background-color: #00797A; 
                        color: white; 
                        text-decoration: none; 
                        border-radius: 3px; 
                    }
                    .lgl-manage-link:hover { background-color: #005f61; color: white; }
                </style>
                
                <h3>Your Subscription Status</h3>
                
                <?php foreach ($subscriptions as $subscription): ?>
                    <?php if ($subscription->has_status('active')): ?>
                        <?php
                        $requires_manual_renewal = $subscription->get_requires_manual_renewal();
                        $subscription_id = $subscription->get_id();
                        $my_account_url = wc_get_account_endpoint_url('view-subscription') . $subscription_id;
                        $next_payment = $subscription->get_date('next_payment');
                        ?>
                        <div class="lgl-subscription-item">
                            <h4>Subscription #<?php echo esc_html($subscription_id); ?></h4>
                            
                            <p><strong>Status:</strong> <?php echo esc_html(ucfirst($subscription->get_status())); ?></p>
                            
                            <?php if ($next_payment): ?>
                                <p><strong>Next Payment:</strong> <?php echo esc_html(date('F j, Y', strtotime($next_payment))); ?></p>
                            <?php endif; ?>
                            
                            <p>
                                <strong>Auto Renewal:</strong> 
                                <span class="lgl-auto-renew-status <?php echo $requires_manual_renewal ? 'lgl-auto-renew-off' : 'lgl-auto-renew-on'; ?>">
                                    <?php echo $requires_manual_renewal ? '❌ OFF' : '✅ ON'; ?>
                                </span>
                            </p>
                            
                            <?php if ($requires_manual_renewal): ?>
                                <p><em>⚠️ You will need to manually renew this subscription before it expires.</em></p>
                            <?php else: ?>
                                <p><em>✅ This subscription will automatically renew on the next payment date.</em></p>
                            <?php endif; ?>
                            
                            <a href="<?php echo esc_url($my_account_url); ?>" class="lgl-manage-link">
                                🔧 Manage Subscription
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php
            return ob_get_clean();
            
        } catch (\Exception $e) {
            error_log('LGL Subscription Display Error: ' . $e->getMessage());
            return '<div class="notice notice-error"><p>Error loading subscription information. Please try again later.</p></div>';
        }
    }
    
    /**
     * Shortcode handler for subscription renewal
     */
    public static function renewalShortcode(array $atts): string {
        // Reset the option to allow re-running if needed
        if (isset($atts['reset']) && $atts['reset'] === 'true') {
            update_option(static::UPDATE_OPTION_KEY, false);
        }
        
        error_log('LGL Subscription Renewal: Shortcode executed');
        return static::runSubscriptionRenewalChangeOnce();
    }
    
    /**
     * Shortcode handler for displaying manual renewal status
     */
    public static function displayManualRenewalShortcode(array $atts): string {
        return static::displayManualRenewalStatus();
    }
    
    /**
     * Legacy shortcode support (deprecated)
     */
    public static function legacyRenewalShortcode(array $atts): string {
        // Log deprecation warning
        error_log('LGL Subscription Renewal: Legacy shortcode "run_subscription_renewal" used. Please update to "lgl_run_subscription_renewal"');
        return static::renewalShortcode($atts);
    }
    
    /**
     * Legacy shortcode support (deprecated)
     */
    public static function legacyDisplayShortcode(array $atts): string {
        // Log deprecation warning  
        error_log('LGL Subscription Renewal: Legacy shortcode "display_requires_manual_renewal" used. Please update to "lgl_display_manual_renewal_status"');
        return static::displayManualRenewalShortcode($atts);
    }
    
    /**
     * Reset the renewal update flag (for debugging)
     */
    public static function resetRenewalFlag(): void {
        delete_option(static::UPDATE_OPTION_KEY);
        error_log('LGL Subscription Renewal: Update flag reset');
    }
    
    /**
     * Get renewal statistics
     */
    public static function getRenewalStats(): ?array {
        if (!function_exists('wcs_get_subscriptions')) {
            return null;
        }
        
        $all_subscriptions = wcs_get_subscriptions(['subscription_status' => 'active']);
        $manual_count = 0;
        $auto_count = 0;
        
        foreach ($all_subscriptions as $subscription) {
            if ($subscription->get_requires_manual_renewal()) {
                $manual_count++;
            } else {
                $auto_count++;
            }
        }
        
        return [
            'total' => count($all_subscriptions),
            'manual_renewal' => $manual_count,
            'auto_renewal' => $auto_count,
            'update_completed' => get_option(static::UPDATE_OPTION_KEY, false)
        ];
    }
    
    /**
     * Check if WooCommerce Subscriptions is available
     */
    public static function isWooCommerceSubscriptionsActive(): bool {
        return function_exists('wcs_get_subscriptions');
    }
    
    /**
     * Get subscription renewal status for a specific user
     */
    public static function getUserSubscriptionStatus(int $user_id): array {
        if (!static::isWooCommerceSubscriptionsActive()) {
            return ['error' => 'WooCommerce Subscriptions not active'];
        }
        
        $subscriptions = wcs_get_users_subscriptions($user_id);
        $status = [
            'total_subscriptions' => count($subscriptions),
            'active_subscriptions' => 0,
            'manual_renewal_count' => 0,
            'auto_renewal_count' => 0
        ];
        
        foreach ($subscriptions as $subscription) {
            if ($subscription->has_status('active')) {
                $status['active_subscriptions']++;
                
                if ($subscription->get_requires_manual_renewal()) {
                    $status['manual_renewal_count']++;
                } else {
                    $status['auto_renewal_count']++;
                }
            }
        }
        
        return $status;
    }
}
