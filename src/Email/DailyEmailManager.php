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

namespace UpstateInternational\LGL\Email;

use UpstateInternational\LGL\Core\CacheManager;
use UpstateInternational\LGL\Core\Utilities;
use UpstateInternational\LGL\Email\WC_Daily_Order_Summary_Email;

/**
 * Daily Email Manager Class
 * 
 * Manages daily order summary emails with proper caching and error handling
 */
class DailyEmailManager {
    
    /**
     * Email event hook name
     */
    const EMAIL_HOOK = 'lgl_send_daily_order_summary';
    
    /**
     * Default recipients
     */
    const DEFAULT_RECIPIENTS = [
        'andrew@21adsmedia.com',
        'finance@upstateinternational.org',
        'info@upstateinternational.org'
    ];
    
    /**
     * Initialize the daily email system
     */
    public static function init(): void {
        add_action(static::EMAIL_HOOK, [static::class, 'sendDailySummary']);
        
        // Schedule daily email if not already scheduled
        if (!wp_next_scheduled(static::EMAIL_HOOK)) {
            wp_schedule_event(time(), 'daily', static::EMAIL_HOOK);
        }
        
        // Clean up old scheduled events from theme
        if (wp_next_scheduled('send_daily_order_summary_email_event')) {
            wp_clear_scheduled_hook('send_daily_order_summary_email_event');
        }
        
        // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email Manager: Initialized successfully');
    }
    
    /**
     * Send daily order summary email
     * 
     * @param \DateTime|null $start_datetime Start date for orders
     * @param \DateTime|null $end_datetime End date for orders
     * @param string|array|null $recipient_email Email recipients
     * @param array|null $orders Pre-fetched orders (optional)
     */
    public static function sendDailySummary(
        ?\DateTime $start_datetime = null, 
        ?\DateTime $end_datetime = null, 
        $recipient_email = null, 
        ?array $orders = null
    ): void {
        try {
            // Set default recipients
            if (!$recipient_email) {
                $recipients = static::DEFAULT_RECIPIENTS;
            } elseif (!is_array($recipient_email)) {
                $recipients = [$recipient_email];
            } else {
                $recipients = $recipient_email;
            }
            
            // Set default date range (yesterday)
            if (!$start_datetime || !$end_datetime) {
                $start_datetime = new \DateTime('yesterday 00:00:00');
                $end_datetime = new \DateTime('yesterday 23:59:59');
            }
            
            // Get orders if not provided
            if ($orders === null) {
                $orders = static::getFilteredOrders($start_datetime, $end_datetime);
            }
            
            if (empty($orders)) {
                \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email: No orders found for ' . $start_datetime->format('Y-m-d'));
                return;
            }
            
            // Use WooCommerce email class if available
            if (class_exists('WC_Email') && class_exists('\UpstateInternational\LGL\Email\WC_Daily_Order_Summary_Email')) {
                $wc_email = new WC_Daily_Order_Summary_Email();
                $wc_email->trigger($orders, $start_datetime, $end_datetime, $recipients);
                \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email: Sent via WC_Email');
            } else {
                // Fallback to wp_mail if WooCommerce not available
                $subject = sprintf(
                    'Daily Order Summary - %s',
                    $start_datetime->format('M j, Y')
                );
                
                $email_content = static::buildEmailContent($orders, $start_datetime, $end_datetime);
                
                $headers = [
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
                ];
                
                // Send to each recipient
                foreach ($recipients as $recipient) {
                    if (is_email($recipient)) {
                        $sent = wp_mail($recipient, $subject, $email_content, $headers);
                        if ($sent) {
                            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email: Successfully sent to ' . $recipient);
                        } else {
                            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email: Failed to send to ' . $recipient);
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get filtered orders with caching
     */
    private static function getFilteredOrders(\DateTime $start_datetime, \DateTime $end_datetime): array {
        $cache_key = 'lgl_daily_orders_' . $start_datetime->format('Ymd') . '_' . $end_datetime->format('Ymd');
        
        $orders = CacheManager::get($cache_key);
        if (false === $orders) {
            $orders = Utilities::getFilteredOrders($start_datetime, $end_datetime);
            
            // Cache for 2 hours
            CacheManager::set($cache_key, $orders, 7200);
        }
        
        return $orders;
    }
    
    /**
     * Build comprehensive email content
     */
    private static function buildEmailContent(array $orders, \DateTime $start_datetime, \DateTime $end_datetime): string {
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
                            <th>Status</th>
                            <th>LGL ID</th>
                            <th>Membership Type</th>
                            <th>Subscription Status</th>
                            <th>Membership Start</th>
                            <th>Renewal Date</th>
                            <th>Subscription ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php $order_data = static::formatOrderData($order); ?>
                            <?php if ($order_data): ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html($order_data['order_id']); ?></strong></td>
                                    <td><?php echo esc_html($order_data['date']); ?></td>
                                    <td><?php echo esc_html($order_data['customer_name']); ?></td>
                                    <td><strong><?php echo Utilities::formatPrice($order_data['total']); ?></strong></td>
                                    <td><?php echo esc_html($order_data['item_count']); ?></td>
                                    <td><?php echo esc_html(ucfirst($order->get_status())); ?></td>
                                    <td><?php echo esc_html($order_data['lgl_id']); ?></td>
                                    <td><?php echo esc_html($order_data['membership_type']); ?></td>
                                    <td><?php echo esc_html($order_data['subscription_status']); ?></td>
                                    <td><?php echo esc_html($order_data['membership_start_date']); ?></td>
                                    <td><?php echo esc_html($order_data['renewal_date']); ?></td>
                                    <td><?php echo esc_html($order_data['subscription_id']); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <h2>📦 Detailed Order Information</h2>
                <?php foreach ($orders as $order): ?>
                    <?php $order_data = static::formatOrderData($order); ?>
                    <?php if ($order_data): ?>
                        <div class="order-detail">
                            <div class="order-header">
                                <h3 style="margin: 0;">Order #<?php echo esc_html($order_data['order_id']); ?> - <?php echo Utilities::formatPrice($order_data['total']); ?></h3>
                                <p style="margin: 5px 0 0 0; color: #666;">
                                    <strong>Customer:</strong> <?php echo esc_html($order_data['customer_name']); ?> | 
                                    <strong>Date:</strong> <?php echo esc_html($order_data['date']); ?> |
                                    <strong>Status:</strong> <?php echo esc_html(ucfirst($order->get_status())); ?>
                                    <?php if ($order_data['lgl_id'] !== 'N/A'): ?>
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
                                        Price: <?php echo Utilities::formatPrice($item->get_total()); ?>
                                    </p>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($order->get_billing_email()): ?>
                                <p><strong>📧 Email:</strong> <?php echo esc_html($order->get_billing_email()); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($order->get_billing_phone()): ?>
                                <p><strong>📞 Phone:</strong> <?php echo esc_html($order->get_billing_phone()); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($order_data['membership_type'] !== 'N/A' || $order_data['subscription_status'] !== 'N/A'): ?>
                                <h4>👤 Membership Information:</h4>
                                <div class="product-list">
                                    <?php if ($order_data['membership_type'] !== 'N/A'): ?>
                                        <p><strong>Membership Type:</strong> <?php echo esc_html($order_data['membership_type']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($order_data['subscription_status'] !== 'N/A'): ?>
                                        <p><strong>Subscription Status:</strong> <?php echo esc_html($order_data['subscription_status']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($order_data['membership_start_date'] !== 'N/A'): ?>
                                        <p><strong>Membership Start Date:</strong> <?php echo esc_html($order_data['membership_start_date']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($order_data['renewal_date'] !== 'N/A'): ?>
                                        <p><strong>Renewal Date:</strong> <?php echo esc_html($order_data['renewal_date']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($order_data['subscription_id'] !== 'N/A'): ?>
                                        <p><strong>Subscription ID:</strong> <?php echo esc_html($order_data['subscription_id']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <div class="footer">
                    <p>
                        📊 <strong>Total Orders:</strong> <?php echo count($orders); ?> | 
                        💰 <strong>Total Revenue:</strong> <?php echo Utilities::formatPrice(array_sum(array_map(function($order) { return $order->get_total(); }, $orders))); ?>
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
    private static function formatOrderData($order): ?array {
        return Utilities::formatOrderData($order);
    }
    
    /**
     * Send test email (for debugging)
     */
    public static function sendTestEmail(?string $recipient = null): void {
        $recipient = $recipient ?: get_option('admin_email');
        
        static::sendDailySummary(
            new \DateTime('yesterday 00:00:00'),
            new \DateTime('yesterday 23:59:59'),
            $recipient
        );
    }
    
    /**
     * Unschedule daily emails (for deactivation)
     */
    public static function unschedule(): void {
        wp_clear_scheduled_hook(static::EMAIL_HOOK);
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email Manager: Unscheduled daily emails');
    }
    
    /**
     * Get email schedule status
     */
    public static function getScheduleStatus(): array {
        $next_scheduled = wp_next_scheduled(static::EMAIL_HOOK);
        
        return [
            'is_scheduled' => (bool) $next_scheduled,
            'next_run' => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : null,
            'hook_name' => static::EMAIL_HOOK,
            'default_recipients' => static::DEFAULT_RECIPIENTS
        ];
    }
    
    /**
     * Manually trigger daily email
     */
    public static function triggerManually(?array $recipients = null): bool {
        try {
            static::sendDailySummary(null, null, $recipients);
            return true;
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Daily Email Manual Trigger Error: ' . $e->getMessage());
            return false;
        }
    }
}
