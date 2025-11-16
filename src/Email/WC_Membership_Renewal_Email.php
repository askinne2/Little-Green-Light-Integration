<?php
/**
 * WooCommerce Email: Membership Renewal Reminder
 * 
 * Custom WC_Email class for membership renewal notifications.
 * Integrates with Kadence WooCommerce Email Designer.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Email;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Admin\SettingsManager;

/**
 * WC_Membership_Renewal_Email Class
 * 
 * Extends WC_Email to integrate with WooCommerce email system
 */
class WC_Membership_Renewal_Email extends \WC_Email {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Settings manager
     * 
     * @var SettingsManager|null
     */
    private ?SettingsManager $settingsManager = null;
    
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
        $this->id = 'lgl_membership_renewal';
        $this->title = 'Membership Renewal Reminder';
        $this->description = 'Membership renewal reminder emails sent to members without active WooCommerce subscriptions.';
        $this->customer_email = true;
        $this->template_html = 'emails/lgl-membership-renewal.php';
        $this->template_plain = 'emails/plain/lgl-membership-renewal.php';
        $this->placeholders = [
            '{first_name}' => '',
            '{last_name}' => '',
            '{renewal_date}' => '',
            '{days_until_renewal}' => '',
            '{membership_level}' => '',
        ];
        
        // Initialize helper
        $this->helper = Helper::getInstance();
        
        // Call parent constructor
        parent::__construct();
        
        // Enable email by default (can be disabled in WooCommerce settings)
        $this->enabled = 'yes';
        
        // Set email type to HTML (not plain text)
        $this->email_type = 'html';
        
        // Set default recipient (will be overridden when triggered)
        $this->recipient = '';
    }
    
    /**
     * Set settings manager
     * 
     * @param SettingsManager $settingsManager Settings manager instance
     * @return void
     */
    public function setSettingsManager(SettingsManager $settingsManager): void {
        $this->settingsManager = $settingsManager;
    }
    
    /**
     * Trigger email
     * 
     * @param string $user_email Recipient email
     * @param string $first_name First name
     * @param int $days_until_renewal Days until renewal
     * @param array $additional_data Additional data (optional)
     * @return void
     */
    public function trigger($user_email, $first_name, $days_until_renewal, $additional_data = []) {
        // Always allow sending (can be controlled via WooCommerce settings, but don't block here)
        // This ensures test emails and programmatic sends work even if admin hasn't enabled in WC settings
        if (!$this->get_recipient() && !$user_email) {
            return;
        }
        
        $this->recipient = $user_email;
        $this->email_data = array_merge([
            'first_name' => $first_name ?: 'Member',
            'last_name' => $additional_data['last_name'] ?? '',
            'days_until_renewal' => $days_until_renewal,
            'membership_level' => $additional_data['membership_level'] ?? '',
            'renewal_date' => $additional_data['renewal_date'] ?? '',
        ], $additional_data);
        
        // Set placeholders
        $this->placeholders['{first_name}'] = $this->email_data['first_name'];
        $this->placeholders['{last_name}'] = $this->email_data['last_name'];
        $this->placeholders['{days_until_renewal}'] = (string) $days_until_renewal;
        $this->placeholders['{membership_level}'] = $this->email_data['membership_level'];
        $this->placeholders['{renewal_date}'] = $this->email_data['renewal_date'];
        
        // Get subject and heading from settings or use defaults
        $interval_key = $this->mapDaysToInterval($days_until_renewal);
        
        if ($this->settingsManager) {
            $settings = $this->settingsManager->getAll();
            $subject_template = $settings["renewal_email_subject_{$interval_key}"] ?? '';
            
            if (!empty($subject_template)) {
                $this->subject = str_replace(
                    ['{first_name}', '{last_name}', '{renewal_date}', '{days_until_renewal}', '{membership_level}'],
                    [$this->email_data['first_name'], $this->email_data['last_name'], $this->email_data['renewal_date'], $days_until_renewal, $this->email_data['membership_level']],
                    $subject_template
                );
            } else {
                $this->subject = $this->getDefaultSubject($first_name, $days_until_renewal);
            }
        } else {
            $this->subject = $this->getDefaultSubject($first_name, $days_until_renewal);
        }
        
        $this->heading = $this->getDefaultHeading($days_until_renewal);
        
        if (!$this->get_recipient()) {
            $this->helper->debug('WC_Membership_Renewal_Email: No recipient set');
            return;
        }
        
        // Ensure email type is HTML
        $this->email_type = 'html';
        
        // Debug template path
        $template_path = LGL_PLUGIN_DIR . 'templates/' . $this->template_html;
        $this->helper->debug('WC_Membership_Renewal_Email: Template path', [
            'template_path' => $template_path,
            'template_exists' => file_exists($template_path),
            'email_type' => $this->email_type
        ]);
        
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
        // Try plugin template first, then fall back to WooCommerce template path
        $template_path = LGL_PLUGIN_DIR . 'templates/';
        
        $html = wc_get_template_html(
            $this->template_html,
            [
                'email_heading' => $this->get_heading(),
                'email_data' => $this->email_data,
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
            ],
            '',
            $template_path
        );
        
        // If template not found, try without custom path (WooCommerce default)
        if (empty($html)) {
            $html = wc_get_template_html(
                $this->template_html,
                [
                    'email_heading' => $this->get_heading(),
                    'email_data' => $this->email_data,
                    'sent_to_admin' => false,
                    'plain_text' => false,
                    'email' => $this,
                ]
            );
        }
        
        return $html;
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
                'description' => sprintf('Available placeholders: %s', '{first_name}, {last_name}, {renewal_date}, {days_until_renewal}, {membership_level}'),
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
     * @param string $first_name First name
     * @param int $days_until_renewal Days until renewal
     * @return string
     */
    private function getDefaultSubject(string $first_name, int $days_until_renewal): string {
        $base_subject = $first_name . ', ';
        
        if ($days_until_renewal === -30) {
            return $base_subject . 'Your Upstate International Membership is now INACTIVE';
        } elseif ($days_until_renewal < 0 && $days_until_renewal >= -29) {
            return $base_subject . 'Your Upstate International Membership Renewal Date has passed!';
        } elseif ($days_until_renewal === 0) {
            return $base_subject . 'Your Upstate International Membership Renewal Date is Today!';
        } else {
            return $base_subject . 'Your Upstate International Membership Renewal is Coming!';
        }
    }
    
    /**
     * Get default heading
     * 
     * @param int $days_until_renewal Days until renewal
     * @return string
     */
    private function getDefaultHeading(int $days_until_renewal): string {
        if ($days_until_renewal === -30) {
            return 'Membership Inactive';
        } elseif ($days_until_renewal < 0) {
            return 'Membership Renewal Overdue';
        } elseif ($days_until_renewal === 0) {
            return 'Renewal Date Today';
        } else {
            return 'Membership Renewal Reminder';
        }
    }
    
    /**
     * Get default subject (for form fields)
     * 
     * @return string
     */
    public function get_default_subject() {
        return 'Your Upstate International Membership Renewal is Coming!';
    }
    
    /**
     * Get default heading (for form fields)
     * 
     * @return string
     */
    public function get_default_heading() {
        return 'Membership Renewal Reminder';
    }
    
    /**
     * Map days until renewal to closest interval key
     * 
     * @param int $days Days until renewal
     * @return string Interval key (30, 14, 7, 0, -7, -30)
     */
    private function mapDaysToInterval(int $days): string {
        $intervals = [30, 14, 7, 0, -7, -30];
        
        // Find closest interval
        $closest = $intervals[0];
        $smallest_diff = abs($days - $closest);
        
        foreach ($intervals as $interval) {
            $diff = abs($days - $interval);
            if ($diff < $smallest_diff) {
                $closest = $interval;
                $smallest_diff = $diff;
            }
        }
        
        return (string) $closest;
    }
    
    /**
     * Get email data
     * 
     * @return array
     */
    public function getEmailData(): array {
        return $this->email_data;
    }
    
    /**
     * Get settings manager (for template access)
     * 
     * @return SettingsManager|null
     */
    public function getSettingsManager(): ?SettingsManager {
        return $this->settingsManager;
    }
}

