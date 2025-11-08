<?php
/**
 * Subscription Renewal Management
 * 
 * Comprehensive solution to set all subscriptions to manual renewal
 * and prevent auto-renewals across all subscription statuses.
 * 
 * Compatible with WooCommerce HPOS (High-Performance Order Storage)
 */

/**
 * Display subscription renewal status for current user
 * Shows active and relevant subscriptions (filters out trash/expired by default)
 * 
 * Shortcode: [display_requires_manual_renewal]
 * 
 * Optional parameters:
 *   show_all="yes" - Show all subscriptions including cancelled/expired (but not trash)
 */
function display_requires_manual_renewal($atts) {
    // Get shortcode attributes
    $atts = shortcode_atts(array(
        'show_all' => 'no'
    ), $atts);
    
    // Get the current user ID
    $user_id = get_current_user_id();
    if (!$user_id) {
        return '<div style="padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                ⚠️ <strong>User not logged in</strong></div>';
    }

    // Get ALL subscriptions for the current user
    $all_subscriptions = wcs_get_users_subscriptions($user_id);

    if (empty($all_subscriptions)) {
        return '<div style="padding: 15px; background: #d4edda; border: 1px solid #28a745; border-radius: 4px;">
                ℹ️ <strong>No subscriptions found</strong> for user ID: ' . $user_id . '</div>';
    }

    // Filter subscriptions based on show_all parameter
    $subscriptions = array();
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
            if (!in_array($status, array('active', 'on-hold', 'pending', 'pending-cancel'))) {
                $hidden_count++;
                continue;
            }
        }
        
        $subscriptions[] = $subscription;
    }

    if (empty($subscriptions)) {
        $message = '<div style="padding: 15px; background: #d4edda; border: 1px solid #28a745; border-radius: 4px;">';
        $message .= 'ℹ️ <strong>No active subscriptions found</strong>';
        if ($hidden_count > 0) {
            $message .= '<p style="margin: 10px 0 0 0; font-size: 13px; color: #666;">You have ' . $hidden_count . ' inactive subscription(s) that are not displayed.</p>';
        }
        $message .= '</div>';
        return $message;
    }

    // Build comprehensive output
    $output = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 14px; line-height: 1.5;">';
    $output .= '<h3 style="margin: 0 0 15px 0; font-size: 18px;">🔍 Your Subscription Status</h3>';
    $output .= '<p style="margin: 0 0 10px 0; color: #666;"><strong>Active subscriptions:</strong> ' . count($subscriptions) . '</p>';
    
    if ($hidden_count > 0 && $atts['show_all'] !== 'yes') {
        $output .= '<p style="margin: 0 0 20px 0; font-size: 13px; color: #666;"><em>' . $hidden_count . ' inactive subscription(s) hidden</em></p>';
    } else {
        $output .= '<div style="margin-bottom: 20px;"></div>';
    }

    foreach ($subscriptions as $subscription) {
        $subscription_id = $subscription->get_id();
        $status = $subscription->get_status();
        $requires_manual_renewal = $subscription->get_requires_manual_renewal();
        $payment_method = $subscription->get_payment_method();
        $payment_method_title = $subscription->get_payment_method_title();
        $next_payment = $subscription->get_date('next_payment');
        $last_modified = $subscription->get_date_modified();
        
        // Get items for display
        $items = $subscription->get_items();
        $item_names = array();
        foreach ($items as $item) {
            $item_names[] = $item->get_name();
        }
        $items_display = implode(', ', $item_names);

        // Get raw meta from database to verify
        $raw_meta = $subscription->get_meta('_requires_manual_renewal', true);

        // Color code based on status
        $status_colors = array(
            'active' => '#28a745',
            'on-hold' => '#ffc107',
            'cancelled' => '#6c757d',
            'expired' => '#6c757d',
            'pending-cancel' => '#fd7e14',
            'pending' => '#17a2b8',
        );
        $status_color = isset($status_colors[$status]) ? $status_colors[$status] : '#6c757d';

        $output .= '<div style="margin: 0 0 20px 0; padding: 20px; background: #f8f9fa; border-left: 4px solid ' . $status_color . '; border-radius: 4px;">';
        $output .= '<h4 style="margin: 0 0 15px 0; font-size: 16px;">Subscription #' . $subscription_id . '</h4>';
        
        // Items
        if (!empty($items_display)) {
            $output .= '<p style="margin: 0 0 15px 0; font-weight: 500;">' . esc_html($items_display) . '</p>';
        }
        
        $output .= '<table style="width: 100%; border-collapse: collapse;">';
        
        // Status
        $output .= '<tr><td style="padding: 8px 8px 8px 0; font-weight: 600; width: 180px;">Status:</td>';
        $output .= '<td style="padding: 8px 0;"><span style="background: ' . $status_color . '; color: white; padding: 4px 12px; border-radius: 3px; font-size: 12px; font-weight: 600; text-transform: uppercase;">' . esc_html($status) . '</span></td></tr>';
        
        // Auto-Renew
        $auto_renew_color = $requires_manual_renewal ? '#28a745' : '#dc3545';
        $auto_renew_text = $requires_manual_renewal ? '❌ OFF (Manual Renewal Required)' : '⚠️ ON (Will Auto-Renew)';
        $output .= '<tr><td style="padding: 8px 8px 8px 0; font-weight: 600;">Auto-Renew:</td>';
        $output .= '<td style="padding: 8px 0;"><strong style="color: ' . $auto_renew_color . ';">' . $auto_renew_text . '</strong></td></tr>';
        
        // Payment Method
        if (!empty($payment_method_title)) {
            $output .= '<tr><td style="padding: 8px 8px 8px 0; font-weight: 600;">Payment Method:</td>';
            $output .= '<td style="padding: 8px 0;">' . esc_html($payment_method_title) . '</td></tr>';
        }
        
        // Next Payment
        if ($next_payment && $status === 'active') {
            $output .= '<tr><td style="padding: 8px 8px 8px 0; font-weight: 600;">Next Payment:</td>';
            $output .= '<td style="padding: 8px 0;">' . esc_html($next_payment) . '</td></tr>';
        }
        
        // Last Modified
        $output .= '<tr><td style="padding: 8px 8px 8px 0; font-weight: 600;">Last Modified:</td>';
        $output .= '<td style="padding: 8px 0;">' . esc_html($last_modified) . '</td></tr>';
        
        $output .= '</table>';

        // Manage link
        $my_account_url = wc_get_account_endpoint_url('view-subscription') . $subscription_id;
        $output .= '<p style="margin: 15px 0 0 0;"><a href="' . esc_url($my_account_url) . '" style="display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; font-weight: 600;">View/Manage Subscription →</a></p>';
        $output .= '</div>';
    }

    $output .= '</div>';

    return $output;
}
add_shortcode('display_requires_manual_renewal', 'display_requires_manual_renewal');


/**
 * Comprehensive subscription renewal update function
 * Updates ALL subscriptions regardless of status to require manual renewal
 * Compatible with HPOS
 * 
 * @return array Report of updates
 */
function run_comprehensive_subscription_renewal_update() {
    error_log('=== COMPREHENSIVE SUBSCRIPTION RENEWAL UPDATE STARTED ===');
    error_log('Time: ' . current_time('mysql'));

    // Get ALL subscriptions regardless of status
    $all_statuses = array('active', 'on-hold', 'pending', 'pending-cancel', 'cancelled', 'expired');
    $subscriptions = wcs_get_subscriptions(array(
        'subscription_status' => $all_statuses,
        'subscriptions_per_page' => -1 // Get all subscriptions
    ));

    error_log('Total subscriptions found: ' . count($subscriptions));

    $report = array(
        'total' => 0,
        'updated' => 0,
        'already_manual' => 0,
        'failed' => 0,
        'by_status' => array(),
        'updated_ids' => array(),
        'failed_ids' => array()
    );

    foreach ($subscriptions as $subscription) {
        $subscription_id = $subscription->get_id();
        $status = $subscription->get_status();
        $report['total']++;

        // Track by status
        if (!isset($report['by_status'][$status])) {
            $report['by_status'][$status] = array(
                'total' => 0,
                'updated' => 0,
                'already_manual' => 0,
                'failed' => 0
            );
        }
        $report['by_status'][$status]['total']++;

        // Check current state
        $before = $subscription->get_requires_manual_renewal();
        $db_value_before = $subscription->get_meta('_requires_manual_renewal', true);
        
        error_log(sprintf(
            'Subscription #%d (Status: %s) - Before: requires_manual=%s, meta=%s',
            $subscription_id,
            $status,
            $before ? 'true' : 'false',
            $db_value_before
        ));

        if ($before === true) {
            $report['already_manual']++;
            $report['by_status'][$status]['already_manual']++;
            error_log("Subscription #{$subscription_id} - Already set to manual renewal, skipping");
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

            error_log(sprintf(
                'Subscription #%d - After: requires_manual=%s, meta=%s',
                $subscription_id,
                $after ? 'true' : 'false',
                $db_value_after
            ));

            if ($after === true && ($db_value_after === 'true' || $db_value_after === true)) {
                $report['updated']++;
                $report['by_status'][$status]['updated']++;
                $report['updated_ids'][] = $subscription_id;
                error_log("Subscription #{$subscription_id} - ✓ Successfully updated");
            } else {
                $report['failed']++;
                $report['by_status'][$status]['failed']++;
                $report['failed_ids'][] = $subscription_id;
                error_log("Subscription #{$subscription_id} - ✗ FAILED to update properly (after={$after}, meta={$db_value_after})");
            }
        } catch (Exception $e) {
            $report['failed']++;
            $report['by_status'][$status]['failed']++;
            $report['failed_ids'][] = $subscription_id;
            error_log("Subscription #{$subscription_id} - ✗ Exception: " . $e->getMessage());
        }
    }

    error_log('=== UPDATE COMPLETE ===');
    error_log('Total: ' . $report['total']);
    error_log('Updated: ' . $report['updated']);
    error_log('Already Manual: ' . $report['already_manual']);
    error_log('Failed: ' . $report['failed']);
    error_log('By Status: ' . print_r($report['by_status'], true));
    
    if (!empty($report['failed_ids'])) {
        error_log('Failed IDs: ' . implode(', ', $report['failed_ids']));
    }

    return $report;
}


/**
 * Admin-only shortcode for running the comprehensive update
 * 
 * Shortcode: [admin_update_all_subscriptions]
 * 
 * IMPORTANT: Uncomment the add_shortcode line below ONLY when ready to run on live site
 */
function admin_run_subscription_update_shortcode() {
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

    $report = run_comprehensive_subscription_renewal_update();

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
// UNCOMMENT THE LINE BELOW WHEN READY TO RUN THE UPDATE:
//add_shortcode('admin_update_all_subscriptions', 'admin_run_subscription_update_shortcode');


/**
 * Helper function to set manual renewal on a subscription object
 * Centralizes the logic for enforcing manual renewal
 */
function force_manual_renewal_on_subscription_object($subscription) {
    if ($subscription && is_a($subscription, 'WC_Subscription')) {
        try {
            if ($subscription->get_requires_manual_renewal() !== true) {
                $subscription->set_requires_manual_renewal(true);
                $subscription->save();
                error_log("Auto-enforced manual renewal on subscription #{$subscription->get_id()}");
            }
        } catch (Exception $e) {
            error_log("Error enforcing manual renewal on subscription #{$subscription->get_id()}: " . $e->getMessage());
        }
    }
}

/**
 * Prevention Hook: Force manual renewal on subscription creation
 * Ensures new subscriptions are created with manual renewal enabled
 */
function force_manual_renewal_on_new_subscription($subscription) {
    // Handle case where hook passes subscription ID instead of object
    if (is_int($subscription) || (is_numeric($subscription) && !is_object($subscription))) {
        $subscription_id = (int) $subscription;
        $subscription = wcs_get_subscription($subscription_id);
        
        // If subscription doesn't exist yet, it will be handled by woocommerce_subscription_created hook
        if (!$subscription) {
            error_log("Warning: Subscription #{$subscription_id} not found in force_manual_renewal_on_new_subscription - will be handled by subscription_created hook");
            return;
        }
    }
    
    // Verify we have a subscription object
    if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
        error_log("Warning: force_manual_renewal_on_new_subscription received invalid subscription: " . gettype($subscription));
        return;
    }
    
    force_manual_renewal_on_subscription_object($subscription);
}
add_action('woocommerce_new_subscription', 'force_manual_renewal_on_new_subscription', 10, 1);
// Also hook into subscription_created as backup
add_action('woocommerce_subscription_created', 'force_manual_renewal_on_subscription_object', 10, 1);


/**
 * Prevention Hook: Force manual renewal on subscription status change
 * Ensures subscriptions remain set to manual renewal when status changes
 */
function force_manual_renewal_on_status_change($subscription, $new_status, $old_status) {
    // Handle case where subscription might be an ID
    if (is_int($subscription) || (is_numeric($subscription) && !is_object($subscription))) {
        $subscription = wcs_get_subscription((int) $subscription);
    }
    
    if ($subscription && is_a($subscription, 'WC_Subscription')) {
        try {
            if ($subscription->get_requires_manual_renewal() !== true) {
                $subscription->set_requires_manual_renewal(true);
                $subscription->save();
                error_log("Enforced manual renewal on subscription #{$subscription->get_id()} during status change from {$old_status} to {$new_status}");
            }
        } catch (Exception $e) {
            error_log("Error enforcing manual renewal on subscription status change: " . $e->getMessage());
        }
    }
}
add_action('woocommerce_subscription_status_updated', 'force_manual_renewal_on_status_change', 10, 3);


/**
 * Prevention Hook: Force manual renewal when payment method is updated
 * Prevents auto-renewal from being re-enabled when users update payment methods
 */
function force_manual_renewal_on_payment_update($subscription) {
    // Handle case where subscription might be an ID
    if (is_int($subscription) || (is_numeric($subscription) && !is_object($subscription))) {
        $subscription = wcs_get_subscription((int) $subscription);
    }
    
    if ($subscription && is_a($subscription, 'WC_Subscription')) {
        try {
            if ($subscription->get_requires_manual_renewal() !== true) {
                $subscription->set_requires_manual_renewal(true);
                $subscription->save();
                error_log("Enforced manual renewal on subscription #{$subscription->get_id()} after payment method update");
            }
        } catch (Exception $e) {
            error_log("Error enforcing manual renewal on payment update: " . $e->getMessage());
        }
    }
}
add_action('woocommerce_subscription_payment_method_updated', 'force_manual_renewal_on_payment_update', 10, 1);


/**
 * Admin monitoring shortcode - Shows global subscription renewal statistics
 * 
 * Shortcode: [admin_subscription_stats]
 * 
 * Shows current state of all subscriptions across the site
 */
function admin_subscription_stats_shortcode() {
    if (!current_user_can('manage_options')) {
        return '<div style="padding: 15px; background: #f8d7da; border: 1px solid #dc3545; border-radius: 4px;">
                ❌ <strong>Access Denied:</strong> Admin only</div>';
    }

    // Get all subscriptions
    $all_statuses = array('active', 'on-hold', 'pending', 'pending-cancel', 'cancelled', 'expired');
    $subscriptions = wcs_get_subscriptions(array(
        'subscription_status' => $all_statuses,
        'subscriptions_per_page' => -1
    ));

    if (empty($subscriptions)) {
        return '<div style="padding: 15px; background: #f8d7da; border: 1px solid #dc3545; border-radius: 4px;">
                ❌ <strong>No subscriptions found</strong></div>';
    }

    // Calculate statistics
    $stats = array();
    $total_stats = array(
        'total' => 0,
        'manual' => 0,
        'auto' => 0
    );

    foreach ($subscriptions as $subscription) {
        $status = $subscription->get_status();
        $requires_manual = $subscription->get_requires_manual_renewal();
        
        if (!isset($stats[$status])) {
            $stats[$status] = array(
                'total' => 0,
                'manual' => 0,
                'auto' => 0
            );
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
add_shortcode('admin_subscription_stats', 'admin_subscription_stats_shortcode');
