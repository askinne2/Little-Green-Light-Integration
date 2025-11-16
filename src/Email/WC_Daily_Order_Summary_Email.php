<?php
/**
 * WooCommerce Email: Daily Order Summary
 * 
 * Custom WC_Email class for daily order summary notifications.
 * Integrates with Kadence WooCommerce Email Designer.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Email;

use UpstateInternational\LGL\LGL\Helper;

/**
 * WC_Daily_Order_Summary_Email Class
 * 
 * Extends WC_Email to integrate with WooCommerce email system
 */
class WC_Daily_Order_Summary_Email extends \WC_Email {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Orders data
     * 
     * @var array
     */
    private array $orders = [];
    
    /**
     * Date range
     * 
     * @var array
     */
    private array $date_range = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'lgl_daily_order_summary';
        $this->title = 'Daily Order Summary';
        $this->description = 'Daily order summary emails sent to administrators.';
        $this->customer_email = false; // Admin email
        $this->template_html = 'emails/lgl-daily-order-summary.php';
        $this->template_plain = 'emails/plain/lgl-daily-order-summary.php';
        
        // Initialize helper
        $this->helper = Helper::getInstance();
        
        // Call parent constructor
        parent::__construct();
        
        // Enable email by default
        $this->enabled = 'yes';
        
        // Set default recipient (will be overridden when triggered)
        $this->recipient = '';
    }
    
    /**
     * Trigger email
     * 
     * @param array $orders Orders array
     * @param \DateTime $start_datetime Start date
     * @param \DateTime $end_datetime End date
     * @param string|array $recipients Email recipients
     * @return void
     */
    public function trigger($orders, $start_datetime, $end_datetime, $recipients) {
        // Always allow sending (can be controlled via WooCommerce settings, but don't block here)
        // This ensures dashboard widget emails work even if admin hasn't enabled in WC settings
        
        $this->orders = $orders;
        $this->date_range = [
            'start' => $start_datetime,
            'end' => $end_datetime,
        ];
        
        // Set subject - use "Order Summary" for custom ranges, "Daily Order Summary" for single day
        if ($start_datetime->format('Y-m-d') === $end_datetime->format('Y-m-d')) {
            $this->subject = sprintf(
                'Daily Order Summary - %s',
                $start_datetime->format('M j, Y')
            );
        } else {
            $this->subject = sprintf(
                'Order Summary: %s to %s',
                $start_datetime->format('M j, Y'),
                $end_datetime->format('M j, Y')
            );
        }
        
        $this->heading = 'Order Summary';
        
        // Ensure email type is HTML
        $this->email_type = 'html';
        
        // Handle multiple recipients
        if (is_array($recipients)) {
            foreach ($recipients as $recipient) {
                if (is_email($recipient)) {
                    $this->recipient = $recipient;
                    $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
                }
            }
        } elseif (is_email($recipients)) {
            $this->recipient = $recipients;
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }
    }
    
    /**
     * Get email subject
     * 
     * @return string
     */
    public function get_subject() {
        return apply_filters('woocommerce_email_subject_' . $this->id, $this->format_string($this->subject), $this->object, $this);
    }
    
    /**
     * Get email heading
     * 
     * @return string
     */
    public function get_heading() {
        return apply_filters('woocommerce_email_heading_' . $this->id, $this->format_string($this->heading), $this->object, $this);
    }
    
    /**
     * Get content HTML
     * 
     * @return string
     */
    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            [
                'email_heading' => $this->get_heading(),
                'orders' => $this->orders,
                'date_range' => $this->date_range,
                'sent_to_admin' => true,
                'plain_text' => false,
                'email' => $this,
            ],
            '',
            LGL_PLUGIN_DIR . 'templates/'
        );
    }
    
    /**
     * Get content plain
     * 
     * @return string
     */
    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            [
                'email_heading' => $this->get_heading(),
                'orders' => $this->orders,
                'date_range' => $this->date_range,
                'sent_to_admin' => true,
                'plain_text' => true,
                'email' => $this,
            ],
            '',
            LGL_PLUGIN_DIR . 'templates/'
        );
    }
    
    /**
     * Initialize form fields
     * 
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable this email notification',
                'default' => 'yes',
            ],
            'subject' => [
                'title' => 'Subject',
                'type' => 'text',
                'description' => 'Subject line for daily order summary emails.',
                'placeholder' => $this->get_default_subject(),
                'default' => '',
            ],
            'heading' => [
                'title' => 'Email Heading',
                'type' => 'text',
                'description' => 'Heading for daily order summary emails.',
                'placeholder' => $this->get_default_heading(),
                'default' => '',
            ],
        ];
    }
    
    /**
     * Get default subject
     * 
     * @return string
     */
    public function get_default_subject() {
        return 'Daily Order Summary';
    }
    
    /**
     * Get default heading
     * 
     * @return string
     */
    public function get_default_heading() {
        return 'Daily Order Summary';
    }
    
    /**
     * Get orders
     * 
     * @return array
     */
    public function getOrders(): array {
        return $this->orders;
    }
    
    /**
     * Get date range
     * 
     * @return array
     */
    public function getDateRange(): array {
        return $this->date_range;
    }
}

