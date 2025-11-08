<?php
/**
 * Membership Notification Mailer
 * 
 * Handles sending membership renewal notifications with dynamic content based on renewal status.
 * Modernized version of UI_Memberships_Mailer with proper template system and error handling.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Memberships;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Admin\SettingsManager;

/**
 * MembershipNotificationMailer Class
 * 
 * Manages membership renewal email notifications with template system
 */
class MembershipNotificationMailer {
    
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
     * Email template base path
     * 
     * @var string
     */
    private string $templatePath;
    
    /**
     * Email headers
     * 
     * @var array
     */
    private array $emailHeaders;
    
    /**
     * Site URL for email links
     * 
     * @var string
     */
    private string $siteUrl;
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param string $templatePath Base path for email templates
     * @param SettingsManager|null $settingsManager Settings manager (optional)
     */
    public function __construct(Helper $helper, string $templatePath = '', ?SettingsManager $settingsManager = null) {
        $this->helper = $helper;
        $this->settingsManager = $settingsManager;
        $this->templatePath = $templatePath ?: get_template_directory() . '/form-emails/';
        $this->siteUrl = get_site_url();
        
        $this->emailHeaders = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Upstate International <info@upstateinternational.org>'
        ];
        
        // Set email content type to HTML
        add_filter('wp_mail_content_type', [$this, 'setHtmlContentType']);
    }
    
    /**
     * Send renewal notification based on days until renewal
     * 
     * @param string $recipient_email Recipient email address
     * @param string $first_name Recipient first name
     * @param int $days_until_renewal Days until renewal (negative if overdue)
     * @return bool Success status
     */
    public function sendRenewalNotification(string $recipient_email, string $first_name, int $days_until_renewal): bool {
        if (empty($recipient_email) || !is_email($recipient_email)) {
            $this->helper->debug('Invalid email address: ' . $recipient_email);
            return false;
        }
        
        if (empty($first_name)) {
            $first_name = 'Member';
        }
        
        $subject = $this->getSubjectLine($first_name, $days_until_renewal);
        $content = $this->getEmailContent($days_until_renewal);
        
        $this->helper->debug("Sending renewal email to {$recipient_email}: {$subject}");
        
        try {
            $sent = wp_mail($recipient_email, $subject, $content, $this->emailHeaders);
            
            if ($sent) {
                $this->helper->debug('Email sent successfully');
                return true;
            } else {
                $this->helper->debug('Failed to send email via wp_mail');
                return false;
            }
            
        } catch (\Exception $e) {
            $this->helper->debug('Email sending exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get subject line based on renewal status
     * 
     * @param string $first_name Recipient first name
     * @param int $days_until_renewal Days until renewal
     * @return string Email subject
     */
    private function getSubjectLine(string $first_name, int $days_until_renewal): string {
        // Use settings-driven templates if available
        if ($this->settingsManager) {
            $settings = $this->settingsManager->getAll();
            $interval_key = $this->mapDaysToInterval($days_until_renewal);
            $subject = $settings["renewal_email_subject_{$interval_key}"] ?? '';
            
            if (!empty($subject)) {
                return str_replace('{first_name}', $first_name, $subject);
            }
        }
        
        // Fallback to hardcoded templates
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
     * Get email content based on renewal status
     * 
     * @param int $days_until_renewal Days until renewal
     * @return string Email content HTML
     */
    private function getEmailContent(int $days_until_renewal): string {
        $content = '';
        
        // Use settings-driven templates if available
        if ($this->settingsManager) {
            $settings = $this->settingsManager->getAll();
            $interval_key = $this->mapDaysToInterval($days_until_renewal);
            $template_content = $settings["renewal_email_content_{$interval_key}"] ?? '';
            
            if (!empty($template_content)) {
                // Replace template variables
                $content = str_replace(
                    ['{first_name}', '{last_name}', '{renewal_date}', '{days_until_renewal}', '{membership_level}'],
                    ['', '', '', $days_until_renewal, ''], // Basic replacements (enhanced by caller if needed)
                    $template_content
                );
                
                return $this->wrapContentWithTemplate($content);
            }
        }
        
        // Fallback to hardcoded templates
        if ($days_until_renewal === -30) {
            $content = $this->getInactiveAccountContent();
        } elseif ($days_until_renewal < 0 && $days_until_renewal >= -29) {
            $content = $this->getOverdueContent();
        } elseif ($days_until_renewal === 0) {
            $content = $this->getTodayContent();
        } elseif ($days_until_renewal === 7) {
            $content = $this->getOneWeekContent();
        } elseif ($days_until_renewal === 14) {
            $content = $this->getTwoWeeksContent();
        } elseif ($days_until_renewal === 30) {
            $content = $this->getOneMonthContent();
        } else {
            $content = $this->getGenericReminderContent($days_until_renewal);
        }
        
        return $this->wrapContentWithTemplate($content);
    }
    
    /**
     * Get content for inactive account notification (30 days overdue)
     * 
     * @return string Email content
     */
    private function getInactiveAccountContent(): string {
        return '
            <h1>There\'s an issue with your membership subscription.</h1>
            <h2>Your membership renewal date has passed and your one month grace period to renew your membership has expired.</h2>
            <p><b>Your membership account has been marked as inactive.</b></p>
            <p>If your membership plan includes family members, their accounts have also been marked as inactive.</p>
            <p>After a 60 day period of inactivity, all user data for your account and family members\' accounts will be permanently removed from the Upstate International website.</p>
            <h3>To reactivate your account</h3>
            <p>Please follow the following steps:</p>
            <ol>
                <li>Reset your account password using the <a href="' . $this->siteUrl . '/my-account/lost-password">Login & Reset Password form</a>.</li>
                <li>Make a new password and login into your account.</li>
                <li>Add a Membership Level to your cart & complete your online checkout</li>
            </ol>
            <p>If you need to make changes to your membership, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    }
    
    /**
     * Get content for overdue renewal notification
     * 
     * @return string Email content
     */
    private function getOverdueContent(): string {
        return '
            <h1>Please renew your membership - it means the World to UI!</h1>
            <h2>Your membership renewal date has passed.</h2>
            <p>Please login to your <a href="' . $this->siteUrl . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
            <p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>
            ' . $this->getContactInfo();
    }
    
    /**
     * Get content for renewal due today
     * 
     * @return string Email content
     */
    private function getTodayContent(): string {
        return '
            <h1>Today is the day!</h1>
            <h2>Your Upstate International Membership renewal date is today.</h2>
            <p>Please login to your <a href="' . $this->siteUrl . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
            <p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>
            ' . $this->getContactInfo();
    }
    
    /**
     * Get content for one week reminder
     * 
     * @return string Email content
     */
    private function getOneWeekContent(): string {
        return '
            <h1>One more week!</h1>
            <h2>Your Upstate International Membership renewal date is in 7 days.</h2>
            <p>Please login to your <a href="' . $this->siteUrl . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
            <p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>
            ' . $this->getContactInfo();
    }
    
    /**
     * Get content for two weeks reminder
     * 
     * @return string Email content
     */
    private function getTwoWeeksContent(): string {
        return '
            <h1>Two more weeks!</h1>
            <h2>Your Upstate International Membership renewal date is in 14 days.</h2>
            <p>Please login to your <a href="' . $this->siteUrl . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online.</p>
            ' . $this->getContactInfo();
    }
    
    /**
     * Get content for one month reminder
     * 
     * @return string Email content
     */
    private function getOneMonthContent(): string {
        return '
            <h1>One more month!</h1>
            <h2>Your Upstate International Membership renewal date is in 30 days.</h2>
            <p>Please login to your <a href="' . $this->siteUrl . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online.</p>
            ' . $this->getContactInfo();
    }
    
    /**
     * Get generic reminder content for other intervals
     * 
     * @param int $days_until_renewal Days until renewal
     * @return string Email content
     */
    private function getGenericReminderContent(int $days_until_renewal): string {
        $days_text = abs($days_until_renewal) . ' days';
        if ($days_until_renewal < 0) {
            $days_text .= ' ago';
        }
        
        return '
            <h1>Membership Renewal Reminder</h1>
            <h2>Your Upstate International Membership renewal date was ' . $days_text . '.</h2>
            <p>Please login to your <a href="' . $this->siteUrl . '/my-account/">Upstate International online account</a> to renew your membership.</p>
            ' . $this->getContactInfo();
    }
    
    /**
     * Get contact information HTML
     * 
     * @return string Contact info HTML
     */
    private function getContactInfo(): string {
        return '
            <p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    }
    
    /**
     * Wrap content with email template
     * 
     * @param string $content Main email content
     * @return string Complete email HTML
     */
    private function wrapContentWithTemplate(string $content): string {
        $header = $this->loadTemplate('renewal_notice_header.php');
        $footer = $this->loadTemplate('renewal_notice_footer.php');
        
        if ($header || $footer) {
            return $header . $content . $footer;
        }
        
        // Fallback to simple HTML wrapper
        return '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Membership Renewal Notice</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    h1, h2 { color: #2c5aa0; }
                    a { color: #2c5aa0; }
                    ul, ol { margin-left: 20px; }
                </style>
            </head>
            <body>
                ' . $content . '
            </body>
            </html>';
    }
    
    /**
     * Load email template file
     * 
     * @param string $template_name Template filename
     * @return string Template content or empty string if not found
     */
    private function loadTemplate(string $template_name): string {
        $template_file = $this->templatePath . $template_name;
        
        if (file_exists($template_file)) {
            return file_get_contents($template_file);
        }
        
        // Try legacy location
        $legacy_path = dirname(__DIR__, 2) . '/includes/ui_memberships/email_templates/' . $template_name;
        if (file_exists($legacy_path)) {
            return file_get_contents($legacy_path);
        }
        
        return '';
    }
    
    /**
     * Set email content type to HTML
     * 
     * @return string Content type
     */
    public function setHtmlContentType(): string {
        return 'text/html';
    }
    
    /**
     * Get settings manager instance (for testing)
     * 
     * @return SettingsManager|null
     */
    public function getSettingsManager(): ?SettingsManager {
        return $this->settingsManager;
    }
    
    /**
     * Send test email
     * 
     * @param string $recipient_email Test recipient
     * @param int $days_scenario Days scenario to test
     * @return bool Success status
     */
    public function sendTestEmail(string $recipient_email, int $days_scenario = 7): bool {
        return $this->sendRenewalNotification($recipient_email, 'Test User', $days_scenario);
    }
    
    /**
     * Get available email templates
     * 
     * @return array Template information
     */
    public function getAvailableTemplates(): array {
        $templates = [];
        $template_files = ['renewal_notice_header.php', 'renewal_notice_footer.php'];
        
        foreach ($template_files as $file) {
            $path = $this->templatePath . $file;
            $templates[$file] = [
                'exists' => file_exists($path),
                'path' => $path,
                'size' => file_exists($path) ? filesize($path) : 0
            ];
        }
        
        return $templates;
    }
    
    /**
     * Get mailer status and configuration
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'template_path' => $this->templatePath,
            'site_url' => $this->siteUrl,
            'templates' => $this->getAvailableTemplates(),
            'wp_mail_available' => function_exists('wp_mail'),
            'email_headers' => $this->emailHeaders
        ];
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
}
