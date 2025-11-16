<?php
/**
 * Daily Order Summary Email Template
 * 
 * This template can be styled by Kadence WooCommerce Email Designer.
 * 
 * @var string $email_heading Email heading
 * @var array $orders Orders array
 * @var array $date_range Date range (start, end)
 * @var bool $sent_to_admin Whether email is sent to admin
 * @var bool $plain_text Whether email is plain text
 * @var WC_Email $email Email object
 */

if (!defined('ABSPATH')) {
    exit;
}

use UpstateInternational\LGL\Core\Utilities;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action('woocommerce_email_header', $email_heading, $email);

$start_datetime = $date_range['start'] ?? new \DateTime('yesterday');
$end_datetime = $date_range['end'] ?? new \DateTime('yesterday');

?>

<p><?php printf(esc_html__('Daily order summary for %s', 'woocommerce'), $start_datetime->format('F j, Y')); ?></p>

<?php if (!empty($orders)): ?>
    <h2><?php esc_html_e('Order Summary', 'woocommerce'); ?></h2>
    
    <table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
        <thead>
            <tr>
                <th class="td" scope="col" style="text-align:left;"><?php esc_html_e('Order ID', 'woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:left;"><?php esc_html_e('Date', 'woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:left;"><?php esc_html_e('Customer', 'woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:left;"><?php esc_html_e('Total', 'woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:left;"><?php esc_html_e('Status', 'woocommerce'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <?php
                if (!is_a($order, 'WC_Order')) {
                    continue;
                }
                $order_data = Utilities::formatOrderData($order);
                ?>
                <tr>
                    <td class="td" style="text-align:left;">
                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>">
                            #<?php echo esc_html($order->get_id()); ?>
                        </a>
                    </td>
                    <td class="td" style="text-align:left;"><?php echo esc_html($order->get_date_created()->date_i18n(wc_date_format())); ?></td>
                    <td class="td" style="text-align:left;"><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></td>
                    <td class="td" style="text-align:left;"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                    <td class="td" style="text-align:left;"><?php echo esc_html(ucfirst($order->get_status())); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th class="td" scope="row" colspan="4" style="text-align:right;"><?php esc_html_e('Total Orders:', 'woocommerce'); ?></th>
                <td class="td" style="text-align:left;">
                    <strong><?php echo count($orders); ?></strong>
                </td>
            </tr>
            <tr>
                <th class="td" scope="row" colspan="4" style="text-align:right;"><?php esc_html_e('Total Revenue:', 'woocommerce'); ?></th>
                <td class="td" style="text-align:left;">
                    <strong><?php echo Utilities::formatPrice(array_sum(array_map(function($order) { return $order->get_total(); }, $orders))); ?></strong>
                </td>
            </tr>
        </tfoot>
    </table>

    <h2><?php esc_html_e('Order Details', 'woocommerce'); ?></h2>
    
    <?php foreach ($orders as $order): ?>
        <?php
        if (!is_a($order, 'WC_Order')) {
            continue;
        }
        ?>
        <h3><?php printf(esc_html__('Order #%s', 'woocommerce'), esc_html($order->get_order_number())); ?></h3>
        
        <?php
        /*
         * @hooked WC_Emails::order_details() Shows the order details table.
         */
        do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
        ?>
        
        <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;" />
    <?php endforeach; ?>
    
<?php else: ?>
    <p><?php esc_html_e('No orders found for this period.', 'woocommerce'); ?></p>
<?php endif; ?>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);

