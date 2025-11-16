<?php
/**
 * Admin Dashboard Widgets
 * 
 * Provides admin dashboard widgets for nonprofit order summaries and events newsletter generation.
 * Moved from theme to plugin for proper separation of concerns.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\Core\CacheManager;
use UpstateInternational\LGL\Core\Utilities;

/**
 * Dashboard Widgets Manager Class
 * 
 * Handles all dashboard widget functionality with proper caching and error handling
 */
class DashboardWidgets {
    
    /**
     * Cache TTL for expensive operations (1 hour)
     */
    const CACHE_TTL = 3600;
    
    /**
     * Initialize dashboard widgets
     */
    public static function init() {
        // Only initialize if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
    
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Dashboard Widgets: Initialized successfully');
    }
    
    /**
     * Register dashboard widgets
     */
    public static function registerWidgets() {
        wp_add_dashboard_widget(
            'lgl_nonprofit_dashboard_widget',
            'Nonprofit Order Summary',
            [static::class, 'renderNonprofitWidget']
        );
        
        wp_add_dashboard_widget(
            'lgl_sync_orders_ccts_widget',
            'Sync Orders to CCTs',
            [static::class, 'renderSyncOrdersCctsWidget']
        );
        
        // Only add subscription widget if WooCommerce Subscriptions is active
        if (class_exists('WC_Subscriptions')) {
            wp_add_dashboard_widget(
                'lgl_subscription_renewal_widget',
                '🔄 Subscription Renewal Status',
                [static::class, 'renderSubscriptionRenewalWidget']
            );
        }
    }
    

    /**
     * Render nonprofit order summary widget
     */
    public static function renderNonprofitWidget() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="error"><p>WooCommerce is required for this widget.</p></div>';
            return;
        }

        try {
            static::handleWidgetFormSubmission();
            static::renderWidgetStyles();
            static::renderDateFilterForm();
            static::renderOrderSummaryTable();
            static::renderEmailForm();
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Dashboard Widget Error: ' . $e->getMessage());
            echo '<div class="error"><p>Error loading widget. Please try again later.</p></div>';
        }
    }
    
    /**
     * Handle form submissions for the widget
     */
    private static function handleWidgetFormSubmission() {
        // Handle email sending with proper nonce verification
        if (isset($_POST['send_summary_email']) && wp_verify_nonce($_POST['_wpnonce'], 'lgl_send_summary_email')) {
            $start_date = new \DateTime(sanitize_text_field($_POST['email_start_date']) . ' 00:00:00');
            $end_date = new \DateTime(sanitize_text_field($_POST['email_end_date']) . ' 23:59:59');
            $recipient = sanitize_email($_POST['email']);
            
            if ($recipient && is_email($recipient)) {
                $orders = static::getFilteredOrders($start_date, $end_date);
                if (!empty($orders)) {
                    static::sendOrderSummaryEmail($start_date, $end_date, $recipient, $orders);
                    echo '<div class="updated"><p>Email sent successfully!</p></div>';
                } else {
                    echo '<div class="error"><p>No orders found for the selected date range.</p></div>';
                }
            } else {
                echo '<div class="error"><p>Please provide a valid email address.</p></div>';
            }
        }
    }
    
    /**
     * Get filtered orders with caching
     */
    private static function getFilteredOrders($start_date, $end_date) {
        return Utilities::getFilteredOrders($start_date, $end_date);
    }
    
    /**
     * Send order summary email
     */
    private static function sendOrderSummaryEmail($start_date, $end_date, $recipient, $orders) {
        // Use WooCommerce email class if available
        if (class_exists('WC_Email') && class_exists('\UpstateInternational\LGL\Email\WC_Daily_Order_Summary_Email')) {
            $wc_email = new \UpstateInternational\LGL\Email\WC_Daily_Order_Summary_Email();
            $wc_email->trigger($orders, $start_date, $end_date, $recipient);
        } else {
            // Fallback to wp_mail if WooCommerce not available
            $subject = sprintf(
                'Order Summary: %s to %s',
                $start_date->format('M j, Y'),
                $end_date->format('M j, Y')
            );
            
            $message = static::buildEmailContent($orders, $start_date, $end_date);
            
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
            ];
            
            wp_mail($recipient, $subject, $message, $headers);
        }
    }
    
    /**
     * Build email content
     */
    private static function buildEmailContent($orders, $start_date, $end_date) {
        ob_start();
        ?>
        <html>
        <body style="font-family: Arial, sans-serif; color: #333;">
            <h2>Order Summary: <?php echo $start_date->format('M j, Y'); ?> to <?php echo $end_date->format('M j, Y'); ?></h2>
            <table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%;">
                <thead>
                    <tr style="background-color: #f2f2f2;">
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Items</th>
                        <th>LGL ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php $order_data = static::formatOrderData($order); ?>
                        <?php if ($order_data): ?>
                            <tr>
                                <td><?php echo esc_html($order_data['order_id']); ?></td>
                                <td><?php echo esc_html($order_data['date']); ?></td>
                                <td><?php echo esc_html($order_data['customer_name']); ?></td>
                                <td><?php echo static::formatPrice($order_data['total']); ?></td>
                                <td><?php echo esc_html($order_data['item_count']); ?></td>
                                <td><?php echo esc_html($order_data['lgl_id']); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                Generated automatically by <?php echo get_bloginfo('name'); ?>
            </p>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Format order data for display
     */
    private static function formatOrderData($order) {
        return Utilities::formatOrderData($order);
    }
    
    /**
     * Format price safely with WooCommerce fallback
     */
    private static function formatPrice($amount) {
        return Utilities::formatPrice($amount);
    }
    
    /**
     * Render widget styles
     */
    private static function renderWidgetStyles() {
        echo '<style>
            .lgl-widget .submit { text-align: right; }
            .lgl-order-table { font-size: 12px; overflow-x: auto; width: 100%; }
            .lgl-order-table th, .lgl-order-table td { padding: 4px; white-space: nowrap; }
            .lgl-order-table th { background-color: #f2f2f2; border-bottom: 2px solid #000; }
            .lgl-order-table td { border-bottom: 1px solid #ddd; }
            .lgl-form-row { display: flex; gap: 10px; margin: 10px 0; flex-wrap: wrap; }
            .lgl-form-field { flex: 1; min-width: 200px; }
        </style>';
    }
    
    /**
     * Render date filter form
     */
    private static function renderDateFilterForm() {
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : 
                     (new \DateTime('yesterday'))->format('Y-m-d');
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : 
                   (new \DateTime('yesterday'))->format('Y-m-d');
        
        echo '<form method="POST" class="lgl-widget">';
        wp_nonce_field('lgl_filter_orders', '_wpnonce');
        echo '<div class="lgl-form-row">';
        echo '<div class="lgl-form-field">';
        echo '<label for="start_date"><strong>Start Date:</strong></label>';
        echo '<input type="date" id="start_date" name="start_date" value="' . esc_attr($start_date) . '">';
        echo '</div>';
        echo '<div class="lgl-form-field">';
        echo '<label for="end_date"><strong>End Date:</strong></label>';
        echo '<input type="date" id="end_date" name="end_date" value="' . esc_attr($end_date) . '">';
        echo '</div>';
        echo '<div class="lgl-form-field" style="align-self: flex-end;">';
        submit_button('Filter Orders', 'secondary', 'filter_orders', false);
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    
    /**
     * Render order summary table
     */
    private static function renderOrderSummaryTable() {
        $start_date = isset($_POST['start_date']) ? new \DateTime(sanitize_text_field($_POST['start_date']) . ' 00:00:00') : new \DateTime('yesterday 00:00:00');
        $end_date = isset($_POST['end_date']) ? new \DateTime(sanitize_text_field($_POST['end_date']) . ' 23:59:59') : new \DateTime('yesterday 23:59:59');
        
        $orders = static::getFilteredOrders($start_date, $end_date);
        
        echo '<h4 style="margin-top: 20px;">Order Summary</h4>';
        echo '<div style="overflow-x: auto;">';
        echo '<table class="widefat fixed lgl-order-table">';
        echo '<thead><tr>';
        echo '<th>Order ID</th><th>Date</th><th>Customer</th><th>Total</th><th>Items</th><th>LGL ID</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($orders as $order) {
            $order_data = static::formatOrderData($order);
            if (!$order_data) continue;
            
            echo '<tr>';
            echo '<td>' . esc_html($order_data['order_id']) . '</td>';
            echo '<td>' . esc_html($order_data['date']) . '</td>';
            echo '<td>' . esc_html($order_data['customer_name']) . '</td>';
            echo '<td>' . static::formatPrice($order_data['total']) . '</td>';
            echo '<td>' . esc_html($order_data['item_count']) . '</td>';
            echo '<td>' . esc_html($order_data['lgl_id']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table></div>';
    }
    
    /**
     * Render email form
     */
    private static function renderEmailForm() {
        echo '<h4 style="margin-top: 20px;">Send Summary Email</h4>';
        echo '<hr>';
        echo '<form method="POST" class="lgl-widget">';
        wp_nonce_field('lgl_send_summary_email', '_wpnonce');
        echo '<div class="lgl-form-row">';
        echo '<div class="lgl-form-field">';
        echo '<label for="email_start_date"><strong>Start Date:</strong></label>';
        echo '<input type="date" id="email_start_date" name="email_start_date" required>';
        echo '</div>';
        echo '<div class="lgl-form-field">';
        echo '<label for="email_end_date"><strong>End Date:</strong></label>';
        echo '<input type="date" id="email_end_date" name="email_end_date" required>';
        echo '</div>';
        echo '</div>';
        echo '<div class="lgl-form-row">';
        echo '<div class="lgl-form-field">';
        echo '<label for="email"><strong>Recipient Email:</strong></label>';
        echo '<input type="email" id="email" name="email" value="' . esc_attr(get_option('admin_email')) . '" required style="width: 100%;">';
        echo '</div>';
        echo '</div>';
        echo '<div class="lgl-form-row">';
        echo '<div class="lgl-form-field">';
        submit_button('Send Summary Email', 'primary', 'send_summary_email', false);
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    
    
    /**
     * Render sync orders to CCTs widget
     */
    public static function renderSyncOrdersCctsWidget() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="error"><p>WooCommerce is required for this widget.</p></div>';
            return;
        }
        
        try {
            static::handleSyncWidgetFormSubmission();
            static::renderSyncWidgetForm();
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Sync Widget Error: ' . $e->getMessage());
            echo '<div class="error"><p>Error loading widget. Please try again later.</p></div>';
        }
    }
    
    /**
     * Handle sync widget form submission
     */
    private static function handleSyncWidgetFormSubmission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        
        // Handle sync orders
        if (isset($_POST['sync_orders']) && wp_verify_nonce($_POST['_wpnonce'], 'lgl_sync_ccts')) {
            $date_from = sanitize_text_field($_POST['date_from'] ?? '2024-12-17');
            $date_to = sanitize_text_field($_POST['date_to'] ?? date('Y-m-d'));
            
            $wpUsers = \UpstateInternational\LGL\LGL\WpUsers::getInstance();
            $result = $wpUsers->syncOrdersToCcts($date_from, $date_to);
            
            echo '<div class="updated"><p><strong>' . esc_html($result) . '</strong></p></div>';
        }
        
        // Handle reset sync
        if (isset($_POST['reset_sync']) && wp_verify_nonce($_POST['_wpnonce'], 'lgl_sync_ccts')) {
            $wpUsers = \UpstateInternational\LGL\LGL\WpUsers::getInstance();
            $result = $wpUsers->resetCctSyncStatus();
            
            echo '<div class="updated"><p><strong>' . esc_html($result) . '</strong></p></div>';
        }
    }
    
    /**
     * Render sync widget form
     */
    private static function renderSyncWidgetForm() {
        $default_from = '2024-12-17';
        $default_to = date('Y-m-d');
        
        echo '<style>
            .lgl-sync-widget { font-family: Arial, sans-serif; }
            .lgl-sync-form-row { display: flex; gap: 10px; margin: 10px 0; flex-wrap: wrap; align-items: center; }
            .lgl-sync-form-field { flex: 1; min-width: 150px; }
            .lgl-sync-buttons { display: flex; gap: 10px; margin-top: 15px; }
            .lgl-sync-info { background: #e8f4f8; border-left: 4px solid #00797A; padding: 12px; margin: 15px 0; border-radius: 4px; font-size: 13px; }
        </style>';
        
        echo '<div class="lgl-sync-widget">';
        echo '<div class="lgl-sync-info">';
        echo '<p style="margin: 0 0 8px 0;"><strong>📋 What this does:</strong></p>';
        echo '<ul style="margin: 0; padding-left: 20px;">';
        echo '<li>Syncs completed orders to JetEngine CCTs</li>';
        echo '<li>Creates event registration records for event orders</li>';
        echo '<li>Creates class registration records for language class orders</li>';
        echo '<li>Skips orders that have already been synced</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<form method="POST" action="">';
        wp_nonce_field('lgl_sync_ccts', '_wpnonce');
        
        echo '<div class="lgl-sync-form-row">';
        echo '<div class="lgl-sync-form-field">';
        echo '<label for="date_from"><strong>From Date:</strong></label><br>';
        echo '<input type="date" id="date_from" name="date_from" value="' . esc_attr($default_from) . '" style="width: 100%;">';
        echo '</div>';
        
        echo '<div class="lgl-sync-form-field">';
        echo '<label for="date_to"><strong>To Date:</strong></label><br>';
        echo '<input type="date" id="date_to" name="date_to" value="' . esc_attr($default_to) . '" style="width: 100%;">';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="lgl-sync-buttons">';
        submit_button('Sync Orders', 'primary', 'sync_orders', false);
        submit_button('Reset Sync Status', 'secondary', 'reset_sync', false);
        echo '</div>';
        
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Render subscription renewal status widget
     */
    public static function renderSubscriptionRenewalWidget() {
        if (!class_exists('WC_Subscriptions') || !function_exists('wcs_get_subscriptions')) {
            echo '<div class="notice notice-error"><p>WooCommerce Subscriptions is required for this widget.</p></div>';
            return;
        }
        
        try {
            echo '<style>
                .lgl-sub-widget { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; }
                .lgl-sub-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
                .lgl-sub-stat-card { padding: 15px; background: #f8f9fa; border-left: 4px solid #007bff; border-radius: 4px; }
                .lgl-sub-stat-card.manual { border-color: #28a745; background: #d4edda; }
                .lgl-sub-stat-card.auto { border-color: #dc3545; background: #f8d7da; }
                .lgl-sub-stat-card.warning { border-color: #ffc107; background: #fff3cd; }
                .lgl-sub-stat-label { font-size: 11px; color: #666; text-transform: uppercase; font-weight: 600; margin-bottom: 5px; }
                .lgl-sub-stat-value { font-size: 28px; font-weight: 600; }
                .lgl-sub-stat-pct { font-size: 12px; color: #666; margin-top: 5px; }
                .lgl-sub-table { width: 100%; border-collapse: collapse; font-size: 12px; }
                .lgl-sub-table th { background: #f8f9fa; padding: 8px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; }
                .lgl-sub-table td { padding: 8px; border-bottom: 1px solid #dee2e6; }
                .lgl-sub-alert { padding: 15px; border-left: 4px solid; border-radius: 4px; margin-top: 15px; }
                .lgl-sub-alert.success { background: #d4edda; border-color: #28a745; }
                .lgl-sub-alert.warning { background: #fff3cd; border-color: #ffc107; }
                .lgl-sub-button { display: inline-block; padding: 8px 16px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 12px; margin-top: 10px; }
                .lgl-sub-button:hover { background: #0056b3; color: white; }
                .lgl-sub-button.danger { background: #dc3545; }
                .lgl-sub-button.danger:hover { background: #c82333; }
            </style>';
            
            echo '<div class="lgl-sub-widget">';
            
            // Get all subscriptions
            $all_statuses = ['active', 'on-hold', 'pending', 'pending-cancel', 'cancelled', 'expired'];
            // @phpstan-ignore-next-line - WooCommerce Subscriptions function, checked above
            $subscriptions = \wcs_get_subscriptions([
                'subscription_status' => $all_statuses,
                'subscriptions_per_page' => -1
            ]);
            
            if (empty($subscriptions)) {
                echo '<div class="notice notice-warning"><p>No subscriptions found in the system.</p></div>';
                echo '</div>';
                return;
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
            
            // Calculate percentages
            $auto_pct = $total_stats['total'] > 0 ? round(($total_stats['auto'] / $total_stats['total']) * 100, 1) : 0;
            $manual_pct = $total_stats['total'] > 0 ? round(($total_stats['manual'] / $total_stats['total']) * 100, 1) : 0;
            
            // Summary stats cards
            echo '<div class="lgl-sub-stats">';
            
            echo '<div class="lgl-sub-stat-card">';
            echo '<div class="lgl-sub-stat-label">Total</div>';
            echo '<div class="lgl-sub-stat-value">' . $total_stats['total'] . '</div>';
            echo '</div>';
            
            echo '<div class="lgl-sub-stat-card manual">';
            echo '<div class="lgl-sub-stat-label">Manual</div>';
            echo '<div class="lgl-sub-stat-value" style="color: #28a745;">' . $total_stats['manual'] . '</div>';
            echo '<div class="lgl-sub-stat-pct">' . $manual_pct . '%</div>';
            echo '</div>';
            
            $card_class = $auto_pct > 50 ? 'auto' : ($auto_pct > 10 ? 'warning' : 'manual');
            $text_color = $auto_pct > 50 ? '#dc3545' : ($auto_pct > 10 ? '#ff6b08' : '#28a745');
            
            echo '<div class="lgl-sub-stat-card ' . $card_class . '">';
            echo '<div class="lgl-sub-stat-label">Auto-Renew</div>';
            echo '<div class="lgl-sub-stat-value" style="color: ' . $text_color . ';">' . $total_stats['auto'] . '</div>';
            echo '<div class="lgl-sub-stat-pct">' . $auto_pct . '%</div>';
            echo '</div>';
            
            echo '</div>';
            
            // Status breakdown table (top 5 statuses)
            echo '<h4 style="margin: 20px 0 10px 0;">Top Subscription Statuses:</h4>';
            
            // Sort by total count and get top 5
            uasort($stats, function($a, $b) {
                return $b['total'] - $a['total'];
            });
            $top_stats = array_slice($stats, 0, 5, true);
            
            echo '<table class="lgl-sub-table">';
            echo '<thead><tr>';
            echo '<th>Status</th><th style="text-align: center;">Total</th><th style="text-align: center;">Manual</th><th style="text-align: center;">Auto</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($top_stats as $status => $counts) {
                $status_auto_pct = $counts['total'] > 0 ? round(($counts['auto'] / $counts['total']) * 100, 1) : 0;
                
                echo '<tr>';
                echo '<td style="text-transform: uppercase; font-weight: 600; font-size: 11px;">' . esc_html($status) . '</td>';
                echo '<td style="text-align: center;">' . $counts['total'] . '</td>';
                echo '<td style="text-align: center; color: #28a745; font-weight: 600;">' . $counts['manual'] . '</td>';
                echo '<td style="text-align: center; color: #dc3545; font-weight: 600;">' . $counts['auto'] . ' <span style="color: #666; font-weight: normal;">(' . $status_auto_pct . '%)</span></td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            
            // Alert based on auto-renew percentage
            if ($auto_pct > 10) {
                echo '<div class="lgl-sub-alert warning">';
                echo '<strong>⚠️ Warning:</strong> ' . $total_stats['auto'] . ' subscriptions (' . $auto_pct . '%) still have auto-renewal enabled.';
                echo '<br><a href="' . admin_url('admin.php?page=lgl-subscription-management') . '" class="lgl-sub-button danger">Run Comprehensive Update</a>';
                echo '</div>';
            } else {
                echo '<div class="lgl-sub-alert success">';
                echo '<strong>✓ Looking Good!</strong> Only ' . $auto_pct . '% of subscriptions have auto-renewal enabled.';
                echo '</div>';
            }
            
            echo '<p style="margin-top: 15px; font-size: 11px; color: #666;"><em>Last updated: ' . current_time('F j, Y g:i a') . '</em></p>';
            echo '</div>';
            
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Subscription Widget Error: ' . $e->getMessage());
            echo '<div class="notice notice-error"><p>Error loading subscription data. Please try again later.</p></div>';
        }
    }
}
