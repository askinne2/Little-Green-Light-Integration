<?php
/**
 * WooCommerce Subscription Renewal Manager
 * 
 * Comprehensive solution to set all subscriptions to manual renewal
 * and prevent auto-renewals across all subscription statuses.
 * Compatible with WooCommerce HPOS (High-Performance Order Storage).
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;

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
        add_shortcode('admin_subscription_stats', [static::class, 'adminSubscriptionStatsShortcode']);
        add_shortcode('admin_update_all_subscriptions', [static::class, 'adminUpdateAllSubscriptionsShortcode']);
        
        // Legacy shortcode support (will be deprecated)
        add_shortcode('run_subscription_renewal', [static::class, 'legacyRenewalShortcode']);
        add_shortcode('display_requires_manual_renewal', [static::class, 'legacyDisplayShortcode']);
        
        
        // Register prevention hooks
        static::registerPreventionHooks();
        
        // Enqueue frontend styles
        add_action('wp_enqueue_scripts', [static::class, 'enqueueStyles']);
        
    }
    
    /**
     * Enqueue frontend styles for subscription status display
     */
    public static function enqueueStyles(): void {
        wp_enqueue_style(
            'lgl-subscription-status',
            plugin_dir_url(dirname(__DIR__)) . 'assets/css/subscription-status.css',
            [],
            '2.0.0'
        );
    }
    
    /**
     * Register prevention hooks to automatically enforce manual renewal
     */
    private static function registerPreventionHooks(): void {
        // Force manual renewal on new subscriptions
        add_action('woocommerce_new_subscription', [static::class, 'forceManualRenewalOnNew'], 10, 1);
        add_action('woocommerce_subscription_created', [static::class, 'forceManualRenewalOnNew'], 10, 1);
        
        // Force manual renewal on status changes
        add_action('woocommerce_subscription_status_updated', [static::class, 'forceManualRenewalOnStatusChange'], 10, 3);
        
        // Force manual renewal on payment method updates
        add_action('woocommerce_subscription_payment_method_updated', [static::class, 'forceManualRenewalOnNew'], 10, 1);
    }
    
    /**
     * Force manual renewal on a subscription object
     * 
     * @param mixed $subscription Subscription object or ID
     */
    public static function forceManualRenewalOnNew($subscription): void {
        // Handle case where hook passes subscription ID instead of object
        if (is_int($subscription) || (is_numeric($subscription) && !is_object($subscription))) {
            $subscription_id = (int) $subscription;
            $subscription = wcs_get_subscription($subscription_id);
            
            // If subscription doesn't exist yet, it will be handled by another hook
            if (!$subscription) {
                Helper::getInstance()->debug("Warning: Subscription #{$subscription_id} not found in forceManualRenewalOnNew - will be handled by another hook");
                return;
            }
        }
        
        // Verify we have a subscription object
        if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
            Helper::getInstance()->debug("Warning: forceManualRenewalOnNew received invalid subscription: " . gettype($subscription));
            return;
        }
        
        try {
            if ($subscription->get_requires_manual_renewal() !== true) {
                $subscription->set_requires_manual_renewal(true);
                $subscription->save();
                Helper::getInstance()->debug("Auto-enforced manual renewal on subscription #{$subscription->get_id()}");
            }
        } catch (\Exception $e) {
            Helper::getInstance()->debug("Error enforcing manual renewal on subscription #{$subscription->get_id()}: " . $e->getMessage());
        }
    }
    
    /**
     * Force manual renewal on subscription status change
     * 
     * @param mixed $subscription Subscription object or ID
     * @param string $new_status New subscription status
     * @param string $old_status Old subscription status
     */
    public static function forceManualRenewalOnStatusChange($subscription, string $new_status, string $old_status): void {
        // Handle case where subscription might be an ID
        if (is_int($subscription) || (is_numeric($subscription) && !is_object($subscription))) {
            $subscription = wcs_get_subscription((int) $subscription);
        }
        
        if ($subscription && is_a($subscription, 'WC_Subscription')) {
            try {
                if ($subscription->get_requires_manual_renewal() !== true) {
                    $subscription->set_requires_manual_renewal(true);
                    $subscription->save();
                    Helper::getInstance()->debug("Enforced manual renewal on subscription #{$subscription->get_id()} during status change from {$old_status} to {$new_status}");
                }
            } catch (\Exception $e) {
                Helper::getInstance()->debug("Error enforcing manual renewal on subscription status change: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Run comprehensive subscription renewal update
     * Updates ALL subscriptions regardless of status to require manual renewal
     * 
     * @return array Report of updates
     */
    public static function runComprehensiveSubscriptionRenewalUpdate(): array {
        Helper::getInstance()->debug('=== COMPREHENSIVE SUBSCRIPTION RENEWAL UPDATE STARTED ===');
        Helper::getInstance()->debug('Time: ' . current_time('mysql'));

        // Get ALL subscriptions regardless of status
        $all_statuses = ['active', 'on-hold', 'pending', 'pending-cancel', 'cancelled', 'expired'];
        $subscriptions = wcs_get_subscriptions([
            'subscription_status' => $all_statuses,
            'subscriptions_per_page' => -1 // Get all subscriptions
        ]);

        Helper::getInstance()->debug('Total subscriptions found: ' . count($subscriptions));

        $report = [
            'total' => 0,
            'updated' => 0,
            'already_manual' => 0,
            'failed' => 0,
            'by_status' => [],
            'updated_ids' => [],
            'failed_ids' => []
        ];

        foreach ($subscriptions as $subscription) {
            $subscription_id = $subscription->get_id();
            $status = $subscription->get_status();
            $report['total']++;

            // Track by status
            if (!isset($report['by_status'][$status])) {
                $report['by_status'][$status] = [
                    'total' => 0,
                    'updated' => 0,
                    'already_manual' => 0,
                    'failed' => 0
                ];
            }
            $report['by_status'][$status]['total']++;

            // Check current state
            $before = $subscription->get_requires_manual_renewal();
            $db_value_before = $subscription->get_meta('_requires_manual_renewal', true);
            
            Helper::getInstance()->debug(sprintf(
                'Subscription #%d (Status: %s) - Before: requires_manual=%s, meta=%s',
                $subscription_id,
                $status,
                $before ? 'true' : 'false',
                $db_value_before
            ));

            if ($before === true) {
                $report['already_manual']++;
                $report['by_status'][$status]['already_manual']++;
                Helper::getInstance()->debug("Subscription #{$subscription_id} - Already set to manual renewal, skipping");
                continue;
            }

            // Update to require manual renewal
            try {
                $subscription->set_requires_manual_renewal(true);
                $subscription->save();

                // Verify it saved
                // Force refresh from database
                $subscription = wcs_get_subscription($subscription_id);
                $after = $subscription->get_requires_manual_renewal();
                $db_value_after = $subscription->get_meta('_requires_manual_renewal', true);

                Helper::getInstance()->debug(sprintf(
                    'Subscription #%d - After: requires_manual=%s, meta=%s',
                    $subscription_id,
                    $after ? 'true' : 'false',
                    $db_value_after
                ));

                if ($after === true && ($db_value_after === 'true' || $db_value_after === true)) {
                    $report['updated']++;
                    $report['by_status'][$status]['updated']++;
                    $report['updated_ids'][] = $subscription_id;
                    Helper::getInstance()->debug("Subscription #{$subscription_id} - ✓ Successfully updated");
                } else {
                    $report['failed']++;
                    $report['by_status'][$status]['failed']++;
                    $report['failed_ids'][] = $subscription_id;
                    Helper::getInstance()->debug("Subscription #{$subscription_id} - ✗ FAILED to update properly (after={$after}, meta={$db_value_after})");
                }
            } catch (\Exception $e) {
                $report['failed']++;
                $report['by_status'][$status]['failed']++;
                $report['failed_ids'][] = $subscription_id;
                Helper::getInstance()->debug("Subscription #{$subscription_id} - ✗ Exception: " . $e->getMessage());
            }
        }

        Helper::getInstance()->debug('=== UPDATE COMPLETE ===');
        Helper::getInstance()->debug('Total: ' . $report['total']);
        Helper::getInstance()->debug('Updated: ' . $report['updated']);
        Helper::getInstance()->debug('Already Manual: ' . $report['already_manual']);
        Helper::getInstance()->debug('Failed: ' . $report['failed']);
        Helper::getInstance()->debug('By Status: ' . print_r($report['by_status'], true));
        
        if (!empty($report['failed_ids'])) {
            Helper::getInstance()->debug('Failed IDs: ' . implode(', ', $report['failed_ids']));
        }

        return $report;
    }
    
    /**
     * Get one-time membership status for a user
     * 
     * @param int $user_id User ID
     * @return array|null Membership status data or null if not found/expired
     */
    private static function getOneTimeMembershipStatus(int $user_id): ?array {
        Helper::getInstance()->debug('🔍 Getting one-time membership status', ['user_id' => $user_id]);
        
        $subscription_status = get_user_meta($user_id, 'user-subscription-status', true);
        $renewal_timestamp = get_user_meta($user_id, 'user-membership-renewal-date', true);
        $start_timestamp = get_user_meta($user_id, 'user-membership-start-date', true);
        $membership_level = get_user_meta($user_id, 'user-membership-type', true);
        
        Helper::getInstance()->debug('📊 User meta values', [
            'user_id' => $user_id,
            'subscription_status' => $subscription_status,
            'renewal_timestamp' => $renewal_timestamp,
            'start_timestamp' => $start_timestamp,
            'membership_level' => $membership_level,
            'renewal_date_formatted' => $renewal_timestamp ? date('Y-m-d H:i:s', $renewal_timestamp) : 'not set',
            'start_date_formatted' => $start_timestamp ? date('Y-m-d H:i:s', $start_timestamp) : 'not set'
        ]);
        
        // Only process one-time memberships
        if ($subscription_status !== 'one-time') {
            Helper::getInstance()->debug('❌ Not a one-time membership', [
                'user_id' => $user_id,
                'subscription_status' => $subscription_status,
                'expected' => 'one-time'
            ]);
            return null;
        }
        
        if (!$renewal_timestamp) {
            Helper::getInstance()->debug('❌ No renewal timestamp found', ['user_id' => $user_id]);
            return null;
        }
        
        // Check if membership is still active
        $now = current_time('timestamp');
        $is_active = $renewal_timestamp > $now;
        
        Helper::getInstance()->debug('⏰ Membership expiration check', [
            'user_id' => $user_id,
            'now' => date('Y-m-d H:i:s', $now),
            'renewal_date' => date('Y-m-d H:i:s', $renewal_timestamp),
            'is_active' => $is_active,
            'seconds_remaining' => $renewal_timestamp - $now
        ]);
        
        if (!$is_active) {
            Helper::getInstance()->debug('❌ Membership has expired', [
                'user_id' => $user_id,
                'expired_on' => date('Y-m-d', $renewal_timestamp)
            ]);
            return null; // Expired membership
        }
        
        // Calculate days remaining
        $days_remaining = ceil(($renewal_timestamp - $now) / DAY_IN_SECONDS);
        
        Helper::getInstance()->debug('📅 Days remaining calculated', [
            'user_id' => $user_id,
            'days_remaining' => $days_remaining
        ]);
        
        // Get last membership order for product name
        $product_name = static::getLastMembershipProductName($user_id);
        
        Helper::getInstance()->debug('🎫 Product name retrieved', [
            'user_id' => $user_id,
            'product_name' => $product_name
        ]);
        
        // Get user roles (ui_teacher, ui_board, ui_vip, etc.)
        $user_roles = static::getUserRoles($user_id);
        
        $status_data = [
            'user_id' => $user_id,
            'membership_level' => $membership_level ?: 'Member',
            'product_name' => $product_name,
            'start_date' => $start_timestamp ? date('F j, Y', $start_timestamp) : 'Unknown',
            'renewal_date' => date('F j, Y', $renewal_timestamp),
            'days_remaining' => $days_remaining,
            'is_active' => true,
            'status' => $days_remaining <= 30 ? 'expiring_soon' : 'active',
            'roles' => $user_roles
        ];
        
        Helper::getInstance()->debug('✅ One-time membership status compiled', [
            'user_id' => $user_id,
            'status_data' => $status_data
        ]);
        
        return $status_data;
    }
    
    /**
     * Get the last membership product name from user's orders
     * 
     * @param int $user_id User ID
     * @return string Product name or default
     */
    private static function getLastMembershipProductName(int $user_id): string {
        Helper::getInstance()->debug('🔍 Getting last membership product name', ['user_id' => $user_id]);
        
        $args = [
            'customer_id' => $user_id,
            'limit' => -1,
            'status' => ['completed', 'processing'],
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        
        $orders = wc_get_orders($args);
        
        Helper::getInstance()->debug('📦 Orders found', [
            'user_id' => $user_id,
            'order_count' => count($orders)
        ]);
        
        foreach ($orders as $order) {
            Helper::getInstance()->debug('🔍 Checking order', [
                'order_id' => $order->get_id(),
                'order_date' => $order->get_date_created()->format('Y-m-d H:i:s')
            ]);
            
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product) {
                    $product_id = $product->get_id();
                    $has_membership_term = has_term('memberships', 'product_cat', $product_id);
                    
                    Helper::getInstance()->debug('🏷️ Checking product', [
                        'product_id' => $product_id,
                        'product_name' => $item->get_name(),
                        'has_membership_category' => $has_membership_term
                    ]);
                    
                    if ($has_membership_term) {
                        Helper::getInstance()->debug('✅ Found membership product', [
                            'product_name' => $item->get_name(),
                            'order_id' => $order->get_id()
                        ]);
                        return $item->get_name();
                    }
                }
            }
        }
        
        Helper::getInstance()->debug('⚠️ No membership product found in orders, using default', [
            'user_id' => $user_id,
            'default' => 'Membership'
        ]);
        
        return 'Membership';
    }
    
    /**
     * Get user roles that are relevant for display (ui_teacher, ui_board, ui_vip, etc.)
     * 
     * @param int $user_id User ID
     * @return array Array of role data with 'slug' and 'label' keys
     */
    private static function getUserRoles(int $user_id): array {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return [];
        }
        
        // Map of role slugs to display labels
        $role_labels = [
            'ui_teacher' => 'Teacher',
            'ui_board' => 'Board Member',
            'ui_vip' => 'VIP',
            'ui_member' => 'Member',
            'ui_patron_owner' => 'Family Owner'
        ];
        
        $relevant_roles = [];
        foreach ($user->roles as $role_slug) {
            // Only include UI-specific roles (not WordPress default roles)
            if (isset($role_labels[$role_slug])) {
                $relevant_roles[] = [
                    'slug' => $role_slug,
                    'label' => $role_labels[$role_slug]
                ];
            }
        }
        
        Helper::getInstance()->debug('👤 User roles retrieved', [
            'user_id' => $user_id,
            'all_roles' => $user->roles,
            'relevant_roles' => $relevant_roles
        ]);
        
        return $relevant_roles;
    }
    
    /**
     * Display roles only (when user has roles but no membership/subscription)
     * 
     * @param array $roles Array of role data
     * @param int $user_id User ID
     * @return string HTML output
     */
    private static function displayRolesOnly(array $roles, int $user_id): string {
        $role_labels = [];
        foreach ($roles as $role) {
            $role_labels[] = esc_html($role['label']);
        }
        
        $output = '<div class="lgl-subscription-status">';
        $output .= '<div class="lgl-membership-card status-active">';
        $output .= '<h4>Your Account Status</h4>';
        $output .= '<table class="lgl-membership-details">';
        $output .= '<tr><td>Roles:</td>';
        $output .= '<td><strong>' . implode(', ', $role_labels) . '</strong></td></tr>';
        $output .= '</table>';
        $output .= '<p class="lgl-mt-15"><em>ℹ️ You have assigned roles but no active membership subscription.</em></p>';
        $output .= '</div>'; // .lgl-membership-card
        $output .= '</div>'; // .lgl-subscription-status
        
        return $output;
    }
    
    /**
     * Display one-time membership status
     * 
     * @param array $status Membership status data
     * @return string HTML output
     */
    private static function displayOneTimeMembershipStatus(array $status): string {
        $status_class = $status['status'] === 'expiring_soon' ? 'status-expiring-soon' : 'status-active';
        $status_badge = $status['status'] === 'expiring_soon' ? 'expiring-soon' : 'active';
        $status_text = $status['status'] === 'expiring_soon' ? 'EXPIRING SOON' : 'ACTIVE';
        
        $output = '<div class="lgl-subscription-status">';
        
        $output .= '<div class="lgl-membership-card ' . $status_class . '">';
        // $output .= '<h4>' . esc_html($status['product_name']) . '</h4>';
        
        $output .= '<table class="lgl-membership-details">';
        
        // Status
        $output .= '<tr><td>Status:</td>';
        $output .= '<td><span class="lgl-status-badge ' . $status_badge . '">' . $status_text . '</span></td></tr>';
        
        // Membership Level
        if (!empty($status['membership_level'])) {
            $output .= '<tr><td>Membership Level:</td>';
            $output .= '<td>' . esc_html($status['membership_level']) . '</td></tr>';
        }
        
        // User Roles (ui_teacher, ui_board, ui_vip, etc.)
        if (!empty($status['roles']) && is_array($status['roles'])) {
            $role_labels = [];
            foreach ($status['roles'] as $role) {
                $role_labels[] = esc_html($role['label']);
            }
            if (!empty($role_labels)) {
                $output .= '<tr><td>Roles:</td>';
                $output .= '<td><strong>' . implode(', ', $role_labels) . '</strong></td></tr>';
            }
        }
        
        // Start Date
        $output .= '<tr><td>Member Since:</td>';
        $output .= '<td>' . esc_html($status['start_date']) . '</td></tr>';
        
        // Renewal Date
        $output .= '<tr><td>Renewal Date:</td>';
        $output .= '<td><strong>' . esc_html($status['renewal_date']) . '</strong></td></tr>';
        
        // Days Remaining
        $days_class = $status['days_remaining'] <= 30 ? 'lgl-text-danger' : 'lgl-text-success';
        $output .= '<tr><td>Days Remaining:</td>';
        $output .= '<td><strong class="' . $days_class . '">' . $status['days_remaining'] . ' days</strong></td></tr>';
        
        // Renewal Type
        $output .= '<tr><td>Renewal Type:</td>';
        $output .= '<td><strong class="lgl-text-success">Manual Renewal Required</strong></td></tr>';
        
        $output .= '</table>';
        
        // Expiring soon warning
        if ($status['status'] === 'expiring_soon') {
            $output .= '<div class="lgl-expiration-warning">';
            $output .= '<strong>⚠️ Your membership expires soon!</strong>';
            $output .= '<span>Renew now to maintain your member benefits.</span>';
            $output .= '</div>';
        }
        
        // Manage link
        $my_account_url = wc_get_account_endpoint_url('orders');
        $output .= '<p class="lgl-mt-15"><a href="' . esc_url($my_account_url) . '" class="lgl-btn">View Orders →</a></p>';
        
        $output .= '</div>'; // .lgl-membership-card
        $output .= '</div>'; // .lgl-subscription-status
        
        return $output;
    }
    
    /**
     * Display manual renewal status for current user with enhanced features
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function displayManualRenewalStatus(array $atts = []): string {
        // Get shortcode attributes
        $atts = shortcode_atts([
            'show_all' => 'no'
        ], $atts);
        
        // Get the current user ID
        $user_id = get_current_user_id();
        
        Helper::getInstance()->debug('🔍 SubscriptionRenewalManager: displayManualRenewalStatus() called', [
            'user_id' => $user_id,
            'show_all' => $atts['show_all']
        ]);
        
        if (!$user_id) {
            Helper::getInstance()->debug('❌ No user logged in');
            return '<div class="lgl-status-notice warning">
                    ⚠️ <strong>User not logged in</strong></div>';
        }

        // Get ALL subscriptions for the current user
        $all_subscriptions = wcs_get_users_subscriptions($user_id);
        
        Helper::getInstance()->debug('📊 WC Subscriptions check', [
            'user_id' => $user_id,
            'subscription_count' => count($all_subscriptions),
            'has_subscriptions' => !empty($all_subscriptions)
        ]);

        // PRIORITY CHECK: Check for one-time membership FIRST (takes priority over WC subscriptions)
        Helper::getInstance()->debug('🔍 Checking for one-time membership (priority check)', [
            'user_id' => $user_id
        ]);
        
        $one_time_status = static::getOneTimeMembershipStatus($user_id);
        
        Helper::getInstance()->debug('📋 One-time membership status result', [
            'user_id' => $user_id,
            'status_found' => !is_null($one_time_status),
            'status_data' => $one_time_status
        ]);
        
        // If one-time membership exists and is active, show ONLY that (hide WC subscriptions)
        if ($one_time_status) {
            Helper::getInstance()->debug('✅ One-time membership found - displaying it (hiding any WC subscriptions)', [
                'user_id' => $user_id,
                'membership_level' => $one_time_status['membership_level'],
                'days_remaining' => $one_time_status['days_remaining'],
                'wc_subscriptions_hidden' => count($all_subscriptions)
            ]);
            return static::displayOneTimeMembershipStatus($one_time_status);
        }
        
        // No one-time membership - proceed to show WC subscriptions if they exist
        Helper::getInstance()->debug('⏭️ No one-time membership found, proceeding to WC subscriptions', [
            'user_id' => $user_id
        ]);
        
        // Check for user roles even if no membership/subscription found
        $user_roles = static::getUserRoles($user_id);
        
        if (empty($all_subscriptions)) {
            Helper::getInstance()->debug('❌ No WC subscriptions or one-time membership found', ['user_id' => $user_id]);
            
            // If user has roles, display them even without membership
            if (!empty($user_roles)) {
                return static::displayRolesOnly($user_roles, $user_id);
            }
            
            return '<div class="lgl-status-notice success">
                    ℹ️ <strong>No active membership found</strong> for user ID: ' . $user_id . '</div>';
        }

        // Filter subscriptions based on show_all parameter
        $subscriptions = [];
        $hidden_count = 0;
        
        foreach ($all_subscriptions as $subscription) {
            $status = $subscription->get_status();
            
            // Always exclude trash
            if ($status === 'trash') {
                $hidden_count++;
                continue;
            }
            
            // If show_all is not enabled, only show active and on-hold
            if ($atts['show_all'] !== 'yes') {
                if (!in_array($status, ['active', 'on-hold', 'pending', 'pending-cancel'])) {
                    $hidden_count++;
                    continue;
                }
            }
            
            $subscriptions[] = $subscription;
        }

        if (empty($subscriptions)) {
            Helper::getInstance()->debug('⚠️ All WC subscriptions filtered out - no active subscriptions to display', [
                'user_id' => $user_id,
                'total_subscriptions' => count($all_subscriptions),
                'hidden_count' => $hidden_count
            ]);
            
            // Note: One-time membership was already checked at priority level above
            $message = '<div class="lgl-status-notice success">';
            $message .= 'ℹ️ <strong>No active subscriptions found</strong>';
            if ($hidden_count > 0) {
                $message .= '<p class="lgl-text-muted lgl-mt-10">You have ' . $hidden_count . ' inactive subscription(s) that are not displayed.</p>';
            }
            $message .= '</div>';
            return $message;
        }

        // Build comprehensive output
        $output = '<div class="lgl-subscription-status">';
        $output .= '<h3>🔍 Your Subscription Status</h3>';
        $output .= '<p class="lgl-summary-stats"><strong>Active subscriptions:</strong> ' . count($subscriptions) . '</p>';
        
        // Display user roles if they exist
        if (!empty($user_roles)) {
            $role_labels = [];
            foreach ($user_roles as $role) {
                $role_labels[] = esc_html($role['label']);
            }
            if (!empty($role_labels)) {
                $output .= '<p class="lgl-summary-stats"><strong>Your Roles:</strong> ' . implode(', ', $role_labels) . '</p>';
            }
        }
        
        if ($hidden_count > 0 && $atts['show_all'] !== 'yes') {
            $output .= '<p class="lgl-hidden-info">' . $hidden_count . ' inactive subscription(s) hidden</p>';
        } else {
            $output .= '<div class="lgl-mb-20"></div>';
        }

        foreach ($subscriptions as $subscription) {
            $subscription_id = $subscription->get_id();
            $status = $subscription->get_status();
            $requires_manual_renewal = $subscription->get_requires_manual_renewal();
            $payment_method_title = $subscription->get_payment_method_title();
            $next_payment = $subscription->get_date('next_payment');
            $last_modified = $subscription->get_date_modified();
            
            // Get items for display
            $items = $subscription->get_items();
            $item_names = [];
            foreach ($items as $item) {
                $item_names[] = $item->get_name();
            }
            $items_display = implode(', ', $item_names);

            // Map status to CSS class
            $status_class = 'status-' . $status;

            $output .= '<div class="lgl-membership-card ' . $status_class . '">';
            $output .= '<h4>Subscription #' . $subscription_id . '</h4>';
            
            // Items
            if (!empty($items_display)) {
                $output .= '<p class="lgl-mb-15" style="font-weight: 500;">' . esc_html($items_display) . '</p>';
            }
            
            $output .= '<table class="lgl-membership-details">';
            
            // Status
            $output .= '<tr><td>Status:</td>';
            $output .= '<td><span class="lgl-status-badge ' . $status . '">' . esc_html($status) . '</span></td></tr>';
            
            // Auto-Renew
            $auto_renew_class = $requires_manual_renewal ? 'lgl-text-success' : 'lgl-text-danger';
            $auto_renew_text = $requires_manual_renewal ? '❌ OFF (Manual Renewal Required)' : '⚠️ ON (Will Auto-Renew)';
            $output .= '<tr><td>Auto-Renew:</td>';
            $output .= '<td><strong class="' . $auto_renew_class . '">' . $auto_renew_text . '</strong></td></tr>';
            
            // Payment Method
            if (!empty($payment_method_title)) {
                $output .= '<tr><td>Payment Method:</td>';
                $output .= '<td>' . esc_html($payment_method_title) . '</td></tr>';
            }
            
            // Next Payment
            if ($next_payment && $status === 'active') {
                $output .= '<tr><td>Next Payment:</td>';
                $output .= '<td>' . esc_html($next_payment) . '</td></tr>';
            }
            
            // Last Modified
            $output .= '<tr><td>Last Modified:</td>';
            $output .= '<td>' . esc_html($last_modified) . '</td></tr>';
            
            $output .= '</table>';

            // Manage link
            $my_account_url = wc_get_account_endpoint_url('view-subscription') . $subscription_id;
            $output .= '<p class="lgl-mt-15"><a href="' . esc_url($my_account_url) . '" class="lgl-btn">View/Manage Subscription →</a></p>';
            $output .= '</div>';
        }

        $output .= '</div>';

        return $output;
    }
    
    /**
     * Admin-only shortcode for showing global subscription renewal statistics
     * 
     * @return string HTML output
     */
    public static function adminSubscriptionStatsShortcode(): string {
        if (!current_user_can('manage_options')) {
            return '<div style="padding: 15px; background: #f8d7da; border: 1px solid #dc3545; border-radius: 4px;">
                    ❌ <strong>Access Denied:</strong> Admin only</div>';
        }

        // Get all subscriptions
        $all_statuses = ['active', 'on-hold', 'pending', 'pending-cancel', 'cancelled', 'expired'];
        $subscriptions = wcs_get_subscriptions([
            'subscription_status' => $all_statuses,
            'subscriptions_per_page' => -1
        ]);

        if (empty($subscriptions)) {
            return '<div style="padding: 15px; background: #f8d7da; border: 1px solid #dc3545; border-radius: 4px;">
                    ❌ <strong>No subscriptions found</strong></div>';
        }

        // Calculate statistics
        $stats = [];
        $total_stats = [
            'total' => 0,
            'manual' => 0,
            'auto' => 0
        ];

        foreach ($subscriptions as $subscription) {
            $status = $subscription->get_status();
            $requires_manual = $subscription->get_requires_manual_renewal();
            
            if (!isset($stats[$status])) {
                $stats[$status] = [
                    'total' => 0,
                    'manual' => 0,
                    'auto' => 0
                ];
            }
            
            $stats[$status]['total']++;
            $total_stats['total']++;
            
            if ($requires_manual) {
                $stats[$status]['manual']++;
                $total_stats['manual']++;
            } else {
                $stats[$status]['auto']++;
                $total_stats['auto']++;
            }
        }

        // Build output
        $output = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 14px;">';
        $output .= '<h3 style="margin: 0 0 20px 0;">📊 Global Subscription Renewal Statistics</h3>';
        
        // Summary stats
        $auto_pct = $total_stats['total'] > 0 ? round(($total_stats['auto'] / $total_stats['total']) * 100, 1) : 0;
        $manual_pct = $total_stats['total'] > 0 ? round(($total_stats['manual'] / $total_stats['total']) * 100, 1) : 0;
        
        $output .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">';
        
        $output .= '<div style="padding: 20px; background: #f8f9fa; border-left: 4px solid #007bff; border-radius: 4px;">';
        $output .= '<div style="font-size: 12px; color: #666; margin-bottom: 5px;">TOTAL SUBSCRIPTIONS</div>';
        $output .= '<div style="font-size: 32px; font-weight: 600;">' . $total_stats['total'] . '</div>';
        $output .= '</div>';
        
        $output .= '<div style="padding: 20px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 4px;">';
        $output .= '<div style="font-size: 12px; color: #666; margin-bottom: 5px;">MANUAL RENEWAL</div>';
        $output .= '<div style="font-size: 32px; font-weight: 600; color: #28a745;">' . $total_stats['manual'] . '</div>';
        $output .= '<div style="font-size: 12px; color: #666;">' . $manual_pct . '%</div>';
        $output .= '</div>';
        
        $bg_color = $auto_pct > 50 ? '#f8d7da' : ($auto_pct > 10 ? '#fff3cd' : '#d4edda');
        $border_color = $auto_pct > 50 ? '#dc3545' : ($auto_pct > 10 ? '#ffc107' : '#28a745');
        $text_color = $auto_pct > 50 ? '#dc3545' : ($auto_pct > 10 ? '#ff6b08' : '#28a745');
        
        $output .= '<div style="padding: 20px; background: ' . $bg_color . '; border-left: 4px solid ' . $border_color . '; border-radius: 4px;">';
        $output .= '<div style="font-size: 12px; color: #666; margin-bottom: 5px;">AUTO-RENEW ENABLED</div>';
        $output .= '<div style="font-size: 32px; font-weight: 600; color: ' . $text_color . ';">' . $total_stats['auto'] . '</div>';
        $output .= '<div style="font-size: 12px; color: #666;">' . $auto_pct . '%</div>';
        $output .= '</div>';
        
        $output .= '</div>';

        // Detailed breakdown by status
        $output .= '<h4 style="margin: 30px 0 15px 0;">Breakdown by Status:</h4>';
        $output .= '<table style="width: 100%; border-collapse: collapse; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
        $output .= '<thead><tr style="background: #f8f9fa;">';
        $output .= '<th style="padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600;">Status</th>';
        $output .= '<th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600;">Total</th>';
        $output .= '<th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600;">Manual</th>';
        $output .= '<th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600;">Auto-Renew</th>';
        $output .= '<th style="padding: 12px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600;">Auto %</th>';
        $output .= '</tr></thead><tbody>';

        // Sort by total count descending
        uasort($stats, function($a, $b) {
            return $b['total'] - $a['total'];
        });

        foreach ($stats as $status => $counts) {
            $status_auto_pct = $counts['total'] > 0 ? round(($counts['auto'] / $counts['total']) * 100, 1) : 0;
            $pct_color = $status_auto_pct > 50 ? '#dc3545' : ($status_auto_pct > 10 ? '#ffc107' : '#28a745');
            
            $output .= '<tr style="border-bottom: 1px solid #dee2e6;">';
            $output .= '<td style="padding: 12px; font-weight: 600; text-transform: uppercase; font-size: 12px;">' . esc_html($status) . '</td>';
            $output .= '<td style="padding: 12px; text-align: center;">' . $counts['total'] . '</td>';
            $output .= '<td style="padding: 12px; text-align: center; color: #28a745; font-weight: 600;">' . $counts['manual'] . '</td>';
            $output .= '<td style="padding: 12px; text-align: center; color: #dc3545; font-weight: 600;">' . $counts['auto'] . '</td>';
            $output .= '<td style="padding: 12px; text-align: center; color: ' . $pct_color . '; font-weight: 600;">' . $status_auto_pct . '%</td>';
            $output .= '</tr>';
        }

        // Totals row
        $output .= '<tr style="background: #f8f9fa; font-weight: 600;">';
        $output .= '<td style="padding: 12px; border-top: 2px solid #dee2e6;">TOTAL</td>';
        $output .= '<td style="padding: 12px; text-align: center; border-top: 2px solid #dee2e6;">' . $total_stats['total'] . '</td>';
        $output .= '<td style="padding: 12px; text-align: center; color: #28a745; border-top: 2px solid #dee2e6;">' . $total_stats['manual'] . '</td>';
        $output .= '<td style="padding: 12px; text-align: center; color: #dc3545; border-top: 2px solid #dee2e6;">' . $total_stats['auto'] . '</td>';
        $output .= '<td style="padding: 12px; text-align: center; color: ' . $text_color . '; border-top: 2px solid #dee2e6;">' . $auto_pct . '%</td>';
        $output .= '</tr>';

        $output .= '</tbody></table>';

        // Warning if auto-renew is still high
        if ($auto_pct > 10) {
            $output .= '<div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">';
            $output .= '<h4 style="margin: 0 0 10px 0;">⚠️ Warning</h4>';
            $output .= '<p style="margin: 0;">' . $total_stats['auto'] . ' subscriptions (' . $auto_pct . '%) still have auto-renewal enabled. ';
            $output .= 'Consider running the comprehensive update to set all subscriptions to manual renewal.</p>';
            $output .= '</div>';
        } else {
            $output .= '<div style="margin-top: 30px; padding: 20px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 4px;">';
            $output .= '<h4 style="margin: 0 0 10px 0;">✓ Looking Good!</h4>';
            $output .= '<p style="margin: 0;">Only ' . $auto_pct . '% of subscriptions have auto-renewal enabled. The prevention hooks should keep this low.</p>';
            $output .= '</div>';
        }

        $output .= '<p style="margin-top: 20px; font-size: 12px; color: #666;"><em>Last updated: ' . current_time('F j, Y g:i a') . '</em></p>';
        $output .= '</div>';

        return $output;
    }
    
    /**
     * Admin-only shortcode for running the comprehensive update
     * 
     * @return string HTML output
     */
    public static function adminUpdateAllSubscriptionsShortcode(): string {
        if (!current_user_can('manage_options')) {
            return '<div style="padding: 15px; background: #f8d7da; border: 1px solid #dc3545; border-radius: 4px;">
                    ❌ <strong>Access Denied:</strong> Admin only</div>';
        }

        // Safety check - require confirmation parameter
        if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
            return '<div style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                    <h3 style="margin: 0 0 15px 0;">⚠️ Comprehensive Subscription Update</h3>
                    <p>This will update ALL subscriptions (across all statuses) to require manual renewal.</p>
                    <p><strong>This action will affect:</strong></p>
                    <ul>
                        <li>Active subscriptions</li>
                        <li>On-hold subscriptions</li>
                        <li>Cancelled subscriptions</li>
                        <li>Pending subscriptions</li>
                        <li>All other subscription statuses</li>
                    </ul>
                    <p><a href="' . add_query_arg('confirm', 'yes') . '" style="display: inline-block; padding: 12px 24px; background: #dc3545; color: white; text-decoration: none; border-radius: 4px; font-weight: 600;">⚠️ Confirm and Run Update</a></p>
                    </div>';
        }

        $report = static::runComprehensiveSubscriptionRenewalUpdate();

        $output = '<div style="padding: 20px; background: #d4edda; border: 1px solid #28a745; border-radius: 4px;">';
        $output .= '<h3 style="margin: 0 0 15px 0;">✓ Subscription Update Complete</h3>';
        $output .= '<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">';
        $output .= '<tr><td style="padding: 8px; font-weight: 600; border-bottom: 1px solid #ddd;">Total Subscriptions:</td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . $report['total'] . '</td></tr>';
        $output .= '<tr><td style="padding: 8px; font-weight: 600; border-bottom: 1px solid #ddd;">Successfully Updated:</td><td style="padding: 8px; border-bottom: 1px solid #ddd; color: #28a745; font-weight: 600;">' . $report['updated'] . '</td></tr>';
        $output .= '<tr><td style="padding: 8px; font-weight: 600; border-bottom: 1px solid #ddd;">Already Manual:</td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . $report['already_manual'] . '</td></tr>';
        $output .= '<tr><td style="padding: 8px; font-weight: 600; border-bottom: 1px solid #ddd;">Failed:</td><td style="padding: 8px; border-bottom: 1px solid #ddd; color: ' . ($report['failed'] > 0 ? '#dc3545' : '#28a745') . '; font-weight: 600;">' . $report['failed'] . '</td></tr>';
        $output .= '</table>';

        if (!empty($report['by_status'])) {
            $output .= '<h4 style="margin: 20px 0 10px 0;">Breakdown by Status:</h4>';
            $output .= '<table style="width: 100%; border-collapse: collapse;">';
            $output .= '<tr style="background: #f8f9fa;"><th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Status</th><th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Total</th><th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Updated</th><th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Already Manual</th><th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Failed</th></tr>';
            foreach ($report['by_status'] as $status => $stats) {
                $output .= '<tr>';
                $output .= '<td style="padding: 8px; border: 1px solid #ddd; font-weight: 600;">' . esc_html($status) . '</td>';
                $output .= '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;">' . $stats['total'] . '</td>';
                $output .= '<td style="padding: 8px; text-align: center; border: 1px solid #ddd; color: #28a745; font-weight: 600;">' . $stats['updated'] . '</td>';
                $output .= '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;">' . $stats['already_manual'] . '</td>';
                $output .= '<td style="padding: 8px; text-align: center; border: 1px solid #ddd;">' . $stats['failed'] . '</td>';
                $output .= '</tr>';
            }
            $output .= '</table>';
        }

        $output .= '<p style="margin: 20px 0 0 0;"><em>📋 Check error logs for detailed information about each subscription.</em></p>';
        $output .= '</div>';

        return $output;
    }
    
    /**
     * Shortcode handler for subscription renewal
     */
    public static function renewalShortcode(array $atts): string {
        // Reset the option to allow re-running if needed
        if (isset($atts['reset']) && $atts['reset'] === 'true') {
            update_option(static::UPDATE_OPTION_KEY, false);
        }
        
        Helper::getInstance()->debug('LGL Subscription Renewal: Shortcode executed');
        
        // Run comprehensive update instead of basic one
        $report = static::runComprehensiveSubscriptionRenewalUpdate();
        
        return sprintf(
            'Subscription renewal update completed. Updated %d, Already Manual: %d, Failed: %d out of %d total subscriptions.',
            $report['updated'],
            $report['already_manual'],
            $report['failed'],
            $report['total']
        );
    }
    
    /**
     * Shortcode handler for displaying manual renewal status
     */
    public static function displayManualRenewalShortcode(array $atts): string {
        return static::displayManualRenewalStatus($atts);
    }
    
    /**
     * Legacy shortcode support (deprecated)
     */
    public static function legacyRenewalShortcode(array $atts): string {
        // Log deprecation warning
        Helper::getInstance()->debug('LGL Subscription Renewal: Legacy shortcode "run_subscription_renewal" used. Please update to "lgl_run_subscription_renewal"');
        return static::renewalShortcode($atts);
    }
    
    /**
     * Legacy shortcode support (deprecated)
     */
    public static function legacyDisplayShortcode(array $atts): string {
        // Log deprecation warning  
        Helper::getInstance()->debug('LGL Subscription Renewal: Legacy shortcode "display_requires_manual_renewal" used. Please update to "lgl_display_manual_renewal_status"');
        return static::displayManualRenewalShortcode($atts);
    }
    
    /**
     * Reset the renewal update flag (for debugging)
     */
    public static function resetRenewalFlag(): void {
        delete_option(static::UPDATE_OPTION_KEY);
        Helper::getInstance()->debug('LGL Subscription Renewal: Update flag reset');
    }
    
    /**
     * Check if WooCommerce Subscriptions is available
     */
    public static function isWooCommerceSubscriptionsActive(): bool {
        return function_exists('wcs_get_subscriptions');
    }
}
