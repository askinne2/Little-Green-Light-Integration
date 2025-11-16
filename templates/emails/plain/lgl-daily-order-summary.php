<?php
/**
 * Daily Order Summary Email Template (Plain Text)
 * 
 * @var string $email_heading Email heading
 * @var array $orders Orders array
 * @var array $date_range Date range
 * @var bool $sent_to_admin Whether email is sent to admin
 * @var bool $plain_text Whether email is plain text
 * @var WC_Email $email Email object
 */

if (!defined('ABSPATH')) {
    exit;
}

use UpstateInternational\LGL\Core\Utilities;

echo "= " . $email_heading . " =\n\n";

$start_datetime = $date_range['start'] ?? new \DateTime('yesterday');

echo sprintf(__('Daily order summary for %s', 'woocommerce'), $start_datetime->format('F j, Y')) . "\n\n";

if (!empty($orders)) {
    echo "ORDER SUMMARY\n";
    echo str_repeat("=", 50) . "\n\n";
    
    foreach ($orders as $order) {
        if (!is_a($order, 'WC_Order')) {
            continue;
        }
        
        echo "Order #" . $order->get_order_number() . "\n";
        echo "Date: " . $order->get_date_created()->date_i18n(wc_date_format()) . "\n";
        echo "Customer: " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n";
        echo "Total: " . $order->get_formatted_order_total() . "\n";
        echo "Status: " . ucfirst($order->get_status()) . "\n";
        echo str_repeat("-", 50) . "\n\n";
    }
    
    echo "Total Orders: " . count($orders) . "\n";
    echo "Total Revenue: " . Utilities::formatPrice(array_sum(array_map(function($order) { return $order->get_total(); }, $orders))) . "\n";
} else {
    echo "No orders found for this period.\n";
}

