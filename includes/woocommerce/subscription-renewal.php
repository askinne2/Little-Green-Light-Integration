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

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Subscription Renewal Manager Class
 * 
 * Manages subscription renewal settings with proper error handling and logging
 */
class LGL_Subscription_Renewal_Manager {
    
    /**
     * Option key for tracking renewal updates
     */
    const UPDATE_OPTION_KEY = 'lgl_subscription_renewal_update_done';
    
    /**
     * Initialize subscription renewal management
     */
    public static function init() {
        // Register shortcodes
        add_shortcode('lgl_run_subscription_renewal', [self::class, 'renewal_shortcode']);
        add_shortcode('lgl_display_manual_renewal_status', [self::class, 'display_manual_renewal_shortcode']);
        
        // Legacy shortcode support (will be deprecated)
        add_shortcode('run_subscription_renewal', [self::class, 'legacy_renewal_shortcode']);
        add_shortcode('display_requires_manual_renewal', [self::class, 'legacy_display_shortcode']);
    }
    
    /**
     * Run subscription renewal change (one-time operation)
     * 
     * @return string Status message
     */
    public static function run_subscription_renewal_change_once() {
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        
        // Check if WooCommerce Subscriptions is active
        if (!function_exists('wcs_get_subscriptions')) {
            $helper->error('LGL Subscription Renewal: WooCommerce Subscriptions not active');
            return 'Error: WooCommerce Subscriptions plugin is required.';
        }
        
        try {
            $helper->info('LGL Subscription Renewal: Starting renewal update process');
            
            // Check if the function has already run
            if (get_option(self::UPDATE_OPTION_KEY)) {
                $helper->info('LGL Subscription Renewal: Update already completed');
                return 'Subscription renewal update already completed.';
            }
            
            // Get all active subscriptions
            $subscriptions = wcs_get_subscriptions(['subscription_status' => 'active']);
            $updated_count = 0;
            
            $helper->info('LGL Subscription Renewal: Found ' . count($subscriptions) . ' active subscriptions');
            
            foreach ($subscriptions as $subscription) {
                $subscription_id = $subscription->get_id();
                
                try {
                    // Update the subscription to require manual renewals
                    $subscription->set_requires_manual_renewal(true);
                    $subscription->save();
                    
                    $updated_count++;
                    
                    // Only log at debug level to reduce verbosity
                    $helper->debug('LGL Subscription Renewal: Updated subscription', [
                        'subscription_id' => $subscription_id,
                        'manual_renewal' => $subscription->get_requires_manual_renewal()
                    ]);
                    
                } catch (Exception $e) {
                    $helper->error('LGL Subscription Renewal: Error updating subscription', [
                        'subscription_id' => $subscription_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Set the flag to indicate the function has been executed
            update_option(self::UPDATE_OPTION_KEY, true);
            
            $message = sprintf(
                'Subscription renewal update completed. Updated %d of %d subscriptions.',
                $updated_count,
                count($subscriptions)
            );
            
            $helper->info('LGL Subscription Renewal: ' . $message);
            return $message;
            
        } catch (Exception $e) {
            $helper->error('LGL Subscription Renewal Error', ['error' => $e->getMessage()]);
            return 'Error occurred during subscription renewal update. Please check logs.';
        }
    }
    
    /**
     * Display manual renewal status for current user
     * 
     * @return string HTML output
     */
    public static function display_manual_renewal_status() {
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
            
        } catch (Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->error('LGL Subscription Display Error', ['error' => $e->getMessage()]);
            return '<div class="notice notice-error"><p>Error loading subscription information. Please try again later.</p></div>';
        }
    }
    
    /**
     * Shortcode handler for subscription renewal
     */
    public static function renewal_shortcode($atts) {
        // Reset the option to allow re-running if needed
        if (isset($atts['reset']) && $atts['reset'] === 'true') {
            update_option(self::UPDATE_OPTION_KEY, false);
        }
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Subscription Renewal: Shortcode executed');
        return self::run_subscription_renewal_change_once();
    }
    
    /**
     * Shortcode handler for displaying manual renewal status
     */
    public static function display_manual_renewal_shortcode($atts) {
        return self::display_manual_renewal_status();
    }
    
    /**
     * Legacy shortcode support (deprecated)
     */
    public static function legacy_renewal_shortcode($atts) {
        // Log deprecation warning
        \UpstateInternational\LGL\LGL\Helper::getInstance()->warning('LGL Subscription Renewal: Legacy shortcode "run_subscription_renewal" used. Please update to "lgl_run_subscription_renewal"');
        return self::renewal_shortcode($atts);
    }
    
    /**
     * Legacy shortcode support (deprecated)
     */
    public static function legacy_display_shortcode($atts) {
        // Log deprecation warning  
        \UpstateInternational\LGL\LGL\Helper::getInstance()->warning('LGL Subscription Renewal: Legacy shortcode "display_requires_manual_renewal" used. Please update to "lgl_display_manual_renewal_status"');
        return self::display_manual_renewal_shortcode($atts);
    }
    
    /**
     * Reset the renewal update flag (for debugging)
     */
    public static function reset_renewal_flag() {
        delete_option(self::UPDATE_OPTION_KEY);
        \UpstateInternational\LGL\LGL\Helper::getInstance()->info('LGL Subscription Renewal: Update flag reset');
    }
    
    /**
     * Get renewal statistics
     */
    public static function get_renewal_stats() {
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
            'update_completed' => get_option(self::UPDATE_OPTION_KEY, false)
        ];
    }
}

// Initialize the subscription renewal manager only if modern version doesn't exist
// The modern version (SubscriptionRenewalManager) is initialized via Plugin.php
if (!class_exists('\UpstateInternational\LGL\WooCommerce\SubscriptionRenewalManager')) {
LGL_Subscription_Renewal_Manager::init();
}
