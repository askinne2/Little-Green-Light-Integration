<?php
/**
 * Daily Order Summary Email Manager
 * 
 * Handles automated daily email summaries of WooCommerce orders.
 * Moved from theme to plugin for proper separation of concerns.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Daily Email Manager Class
 * 
 * Manages daily order summary emails with proper caching and error handling
 */
class LGL_Daily_Email_Manager {
    
    /**
     * Email event hook name
     */
    const EMAIL_HOOK = 'lgl_send_daily_order_summary';
    
    /**
     * Default recipients
     */
    const DEFAULT_RECIPIENTS = [
        'andrew@21adsmedia.com',
        'info@upstateinternational.org'
    ];
    
    /**
     * Initialize the daily email system
     */
    public static function init() {
        add_action(self::EMAIL_HOOK, [self::class, 'send_daily_summary']);
        
        // Schedule daily email if not already scheduled
        if (!wp_next_scheduled(self::EMAIL_HOOK)) {
            wp_schedule_event(time(), 'daily', self::EMAIL_HOOK);
        }
        
        // Clean up old scheduled events from theme
        if (wp_next_scheduled('send_daily_order_summary_email_event')) {
            wp_clear_scheduled_hook('send_daily_order_summary_email_event');
        }
    }
    
    /**
     * Send daily order summary email
     * 
     * @param DateTime|null $start_datetime Start date for orders
     * @param DateTime|null $end_datetime End date for orders
     * @param string|array|null $recipient_email Email recipients
     * @param array|null $orders Pre-fetched orders (optional)
     */
    public static function send_daily_summary($start_datetime = null, $end_datetime = null, $recipient_email = null, $orders = null) {
        try {
            // Set default recipients
            if (!$recipient_email) {
                $recipients = self::DEFAULT_RECIPIENTS;
            } elseif (!is_array($recipient_email)) {
                $recipients = [$recipient_email];
            } else {
                $recipients = $recipient_email;
            }
            
            // Set default date range (yesterday)
            if (!$start_datetime || !$end_datetime) {
                $start_datetime = new DateTime('yesterday 00:00:00');
                $end_datetime = new DateTime('yesterday 23:59:59');
            }
            
            // Get orders if not provided
            if ($orders === null) {
                $orders = self::get_filtered_orders($start_datetime, $end_datetime);
            }
            
            if (empty($orders)) {
                \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email: No orders found for ' . $start_datetime->format('Y-m-d'));
                return;
            }
            
            $subject = sprintf(
                'Daily Order Summary - %s',
                $start_datetime->format('M j, Y')
            );
            
            $email_content = self::build_email_content($orders, $start_datetime, $end_datetime);
            
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
            ];
            
            // Send to each recipient
            foreach ($recipients as $recipient) {
                if (is_email($recipient)) {
                    $sent = wp_mail($recipient, $subject, $email_content, $headers);
                    if ($sent) {
                        \UpstateInternational\LGL\LGL\Helper::getInstance()->info('LGL Daily Email: Successfully sent', ['recipient' => $recipient]);
                    } else {
                        \UpstateInternational\LGL\LGL\Helper::getInstance()->error('LGL Daily Email: Failed to send', ['recipient' => $recipient]);
                    }
                }
            }
            
        } catch (Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->error('LGL Daily Email Error', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get filtered orders with caching
     */
    private static function get_filtered_orders($start_datetime, $end_datetime) {
        $cache_key = 'lgl_daily_orders_' . $start_datetime->format('Ymd') . '_' . $end_datetime->format('Ymd');
        
        $orders = get_transient($cache_key);
        if (false === $orders) {
            $orders = wc_get_orders([
                'status' => ['completed', 'processing', 'on-hold'],
                'date_created' => $start_datetime->format('Y-m-d') . '...' . $end_datetime->format('Y-m-d'),
                'limit' => -1,
            ]);
            
            // Cache for 2 hours
            set_transient($cache_key, $orders, 7200);
        }
        
        return $orders;
    }
    
    /**
     * Build comprehensive email content
     */
    private static function build_email_content($orders, $start_datetime, $end_datetime) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Daily Order Summary</title>
            <style>
                body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
                .header { background-color: #00797A; color: white; padding: 20px; text-align: center; margin-bottom: 20px; }
                .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                .summary-table th { background-color: #f2f2f2; padding: 12px 8px; border: 1px solid #ddd; font-weight: bold; }
                .summary-table td { padding: 8px; border: 1px solid #ddd; }
                .summary-table tr:nth-child(even) { background-color: #f9f9f9; }
                .order-detail { border: 1px solid #ddd; margin-bottom: 20px; padding: 15px; background-color: #fff; }
                .order-header { background-color: #f8f9fa; padding: 10px; margin: -15px -15px 15px -15px; border-bottom: 1px solid #ddd; }
                .product-list { margin-left: 20px; }
                .footer { text-align: center; font-size: 12px; color: #666; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>📊 Daily Order Summary</h1>
                    <p><?php echo $start_datetime->format('F j, Y'); ?> 
                    <?php if ($start_datetime->format('Y-m-d') !== $end_datetime->format('Y-m-d')): ?>
                        - <?php echo $end_datetime->format('F j, Y'); ?>
                    <?php endif; ?></p>
                </div>
                
                <h2>📋 Order Summary Table</h2>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Items</th>
                            <th>LGL ID</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php $order_data = self::format_order_data($order); ?>
                            <?php if ($order_data): ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html($order_data['order_id']); ?></strong></td>
                                    <td><?php echo esc_html($order_data['date']); ?></td>
                                    <td><?php echo esc_html($order_data['customer_name']); ?></td>
                                    <td><strong><?php echo wc_price($order_data['total']); ?></strong></td>
                                    <td><?php echo esc_html($order_data['item_count']); ?></td>
                                    <td><?php echo esc_html($order_data['lgl_id']); ?></td>
                                    <td><?php echo esc_html(ucfirst($order->get_status())); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <h2>📦 Detailed Order Information</h2>
                <?php foreach ($orders as $order): ?>
                    <?php $order_data = self::format_order_data($order); ?>
                    <?php if ($order_data): ?>
                        <div class="order-detail">
                            <div class="order-header">
                                <h3 style="margin: 0;">Order #<?php echo esc_html($order_data['order_id']); ?> - <?php echo wc_price($order_data['total']); ?></h3>
                                <p style="margin: 5px 0 0 0; color: #666;">
                                    <strong>Customer:</strong> <?php echo esc_html($order_data['customer_name']); ?> | 
                                    <strong>Date:</strong> <?php echo esc_html($order_data['date']); ?> |
                                    <strong>Status:</strong> <?php echo esc_html(ucfirst($order->get_status())); ?>
                                    <?php if ($order_data['lgl_id']): ?>
                                        | <strong>LGL ID:</strong> <?php echo esc_html($order_data['lgl_id']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            
                            <h4>🛍️ Products Ordered:</h4>
                            <div class="product-list">
                                <?php foreach ($order_data['items'] as $item): ?>
                                    <p>
                                        <strong><?php echo esc_html($item->get_name()); ?></strong><br>
                                        Quantity: <?php echo esc_html($item->get_quantity()); ?> | 
                                        Price: <?php echo wc_price($item->get_total()); ?>
                                    </p>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($order->get_billing_email()): ?>
                                <p><strong>📧 Email:</strong> <?php echo esc_html($order->get_billing_email()); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($order->get_billing_phone()): ?>
                                <p><strong>📞 Phone:</strong> <?php echo esc_html($order->get_billing_phone()); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <div class="footer">
                    <p>
                        📊 <strong>Total Orders:</strong> <?php echo count($orders); ?> | 
                        💰 <strong>Total Revenue:</strong> <?php echo wc_price(array_sum(array_map(function($order) { return $order->get_total(); }, $orders))); ?>
                    </p>
                    <p>Generated automatically by <?php echo get_bloginfo('name'); ?> on <?php echo current_time('F j, Y \a\t g:i A'); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Format order data for display
     */
    private static function format_order_data($order) {
        if (!$order) return null;
        
        $user_id = $order->get_user_id();
        $lgl_id = $user_id ? get_user_meta($user_id, 'lgl_id', true) : '';
        
        return [
            'order_id' => $order->get_id(),
            'date' => $order->get_date_created()->format('M j, Y g:i A'),
            'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'total' => $order->get_total(),
            'item_count' => $order->get_item_count(),
            'lgl_id' => $lgl_id ?: 'N/A',
            'items' => $order->get_items(),
            'membership_type' => get_post_meta($order->get_id(), '_membership_type', true) ?: 'N/A',
            'subscription_status' => 'N/A', // TODO: Implement if needed
            'membership_start_date' => 'N/A', // TODO: Implement if needed
            'renewal_date' => 'N/A', // TODO: Implement if needed
            'subscription_id' => 'N/A', // TODO: Implement if needed
        ];
    }
    
    /**
     * Send test email (for debugging)
     */
    public static function send_test_email($recipient = null) {
        $recipient = $recipient ?: get_option('admin_email');
        $yesterday = new DateTime('yesterday');
        
        self::send_daily_summary(
            new DateTime('yesterday 00:00:00'),
            new DateTime('yesterday 23:59:59'),
            $recipient
        );
    }
    
    /**
     * Unschedule daily emails (for deactivation)
     */
    public static function unschedule() {
        wp_clear_scheduled_hook(self::EMAIL_HOOK);
    }
}

// Initialize the daily email manager only if modern version doesn't exist
// The modern version (DailyEmailManager) is initialized via ServiceContainer and HookManager
if (!class_exists('\UpstateInternational\LGL\Email\DailyEmailManager')) {
LGL_Daily_Email_Manager::init();
}
