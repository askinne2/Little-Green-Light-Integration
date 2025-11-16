<?php
/**
 * WooCommerce Email: Family Member Welcome
 * 
 * Custom WC_Email class for family member welcome emails.
 * Integrates with Kadence WooCommerce Email Designer.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Email;

use UpstateInternational\LGL\LGL\Helper;

/**
 * WC_Family_Member_Welcome_Email Class
 * 
 * Extends WC_Email to integrate with WooCommerce email system
 */
class WC_Family_Member_Welcome_Email extends \WC_Email {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Email data object
     * 
     * @var array
     */
    private array $email_data = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'lgl_family_member_welcome';
        $this->title = 'Family Member Welcome';
        $this->description = 'Welcome emails sent to new family member accounts.';
        $this->customer_email = true;
        $this->template_html = 'emails/lgl-family-member-welcome.php';
        $this->template_plain = 'emails/plain/lgl-family-member-welcome.php';
        $this->placeholders = [
            '{first_name}' => '',
            '{password_reset_url}' => '',
            '{site_url}' => '',
        ];
        
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
     * @param string $user_email Recipient email
     * @param string $first_name First name
     * @param string $password_reset_url Password reset URL
     * @return void
     */
    public function trigger($user_email, $first_name, $password_reset_url) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $this->recipient = $user_email;
        $this->email_data = [
            'first_name' => $first_name,
            'password_reset_url' => $password_reset_url,
            'site_url' => home_url(),
        ];
        
        // Set placeholders
        $this->placeholders['{first_name}'] = $first_name;
        $this->placeholders['{password_reset_url}'] = $password_reset_url;
        $this->placeholders['{site_url}'] = home_url();
        
        // Set subject and heading
        $this->subject = $this->get_default_subject();
        $this->heading = $this->get_default_heading();
        
        if (!$this->get_recipient()) {
            $this->helper->debug('WC_Family_Member_Welcome_Email: No recipient set');
            return;
        }
        
        $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
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
                'email_data' => $this->email_data,
                'sent_to_admin' => false,
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
                'email_data' => $this->email_data,
                'sent_to_admin' => false,
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
                'description' => sprintf('Available placeholders: %s', '{first_name}'),
                'placeholder' => $this->get_default_subject(),
                'default' => '',
            ],
            'heading' => [
                'title' => 'Email Heading',
                'type' => 'text',
                'description' => sprintf('Available placeholders: %s', '{first_name}'),
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
        return 'Welcome to Upstate International - Set Your Password';
    }
    
    /**
     * Get default heading
     * 
     * @return string
     */
    public function get_default_heading() {
        return 'Welcome to Upstate International';
    }
    
    /**
     * Get email data
     * 
     * @return array
     */
    public function getEmailData(): array {
        return $this->email_data;
    }
}

