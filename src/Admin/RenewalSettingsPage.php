<?php
/**
 * Renewal Settings Page
 * 
 * Admin interface for configuring membership renewal reminders and email templates.
 * Provides email editor, test functionality, and statistics display.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Memberships\RenewalStrategyManager;
use UpstateInternational\LGL\Memberships\MembershipNotificationMailer;

/**
 * RenewalSettingsPage Class
 * 
 * Manages renewal reminder settings admin interface
 */
class RenewalSettingsPage {
    
    /**
     * Settings manager
     * 
     * @var SettingsManager
     */
    private SettingsManager $settingsManager;
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Renewal strategy manager
     * 
     * @var RenewalStrategyManager
     */
    private RenewalStrategyManager $strategyManager;
    
    /**
     * Notification mailer
     * 
     * @var MembershipNotificationMailer|null
     */
    private ?MembershipNotificationMailer $mailer = null;
    
    /**
     * Constructor
     * 
     * @param SettingsManager $settingsManager Settings manager
     * @param Helper $helper Helper service
     * @param RenewalStrategyManager $strategyManager Renewal strategy manager
     * @param MembershipNotificationMailer|null $mailer Notification mailer (optional)
     */
    public function __construct(
        SettingsManager $settingsManager,
        Helper $helper,
        RenewalStrategyManager $strategyManager,
        ?MembershipNotificationMailer $mailer = null
    ) {
        $this->settingsManager = $settingsManager;
        $this->helper = $helper;
        $this->strategyManager = $strategyManager;
        $this->mailer = $mailer;
        
        // Handle form submissions
        add_action('admin_post_lgl_save_renewal_settings', [$this, 'handleSaveSettings']);
        add_action('admin_post_lgl_test_renewal_email', [$this, 'handleTestEmail']);
    }
    
    /**
     * Render the settings page
     * 
     * @return void
     */
    public function render(): void {
        $settings = $this->settingsManager->getAll();
        $wc_active = $this->strategyManager->isWcSubscriptionsActive();
        $stats = $this->strategyManager->getRenewalStatistics();
        
        ?>
        <div class="wrap">
            <h1>Membership Renewal Settings</h1>
            
            <div class="notice notice-info">
                <p><strong>🧪 Need to Test?</strong> Use the <a href="<?php echo admin_url('admin.php?page=lgl-test-renewals'); ?>">Membership Testing Tools</a> to safely test renewal emails without sending to real members. Features include dry-run reports, email previews, and test emails to your admin address.</p>
            </div>
            
            <?php $this->renderStatusNotices($wc_active, $stats); ?>
            
            <?php $this->renderStatistics($stats); ?>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lgl_renewal_settings'); ?>
                <input type="hidden" name="action" value="lgl_save_renewal_settings">
                
                <?php $this->renderGeneralSettings($settings); ?>
                
                <?php $this->renderEmailTemplates($settings); ?>
                
                <?php submit_button('Save Renewal Settings'); ?>
            </form>
            
            <?php $this->renderTestEmailSection(); ?>
        </div>
        <?php
    }
    
    /**
     * Render status notices
     * 
     * @param bool $wc_active Whether WC Subscriptions is active
     * @param array $stats Renewal statistics
     * @return void
     */
    private function renderStatusNotices(bool $wc_active, array $stats): void {
        if ($wc_active): ?>
            <div class="notice notice-info">
                <p><strong>WooCommerce Subscriptions is Active:</strong> 
                For members with active subscriptions (<?php echo esc_html($stats['wc_managed']); ?> members), 
                WooCommerce will handle renewal reminders. These settings only apply to one-time membership 
                purchases (<?php echo esc_html($stats['plugin_managed']); ?> members).</p>
            </div>
        <?php else: ?>
            <div class="notice notice-warning">
                <p><strong>WooCommerce Subscriptions is Not Active:</strong> 
                All membership renewals (<?php echo esc_html($stats['total_members']); ?> members) will be 
                managed by this plugin's reminder system.</p>
            </div>
        <?php endif;
    }
    
    /**
     * Render statistics section
     * 
     * @param array $stats Renewal statistics
     * @return void
     */
    private function renderStatistics(array $stats): void {
        ?>
        <div class="card" style="margin-top: 20px; max-width: 100%;">
            <h2 style="margin: 0 0 15px 0;">Membership Renewal Statistics</h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Count</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Total Members</strong></td>
                        <td><?php echo esc_html($stats['total_members']); ?></td>
                    </tr>
                    <tr>
                        <td>WooCommerce Managed</td>
                        <td><?php echo esc_html($stats['wc_managed']); ?></td>
                    </tr>
                    <tr>
                        <td>Plugin Managed (One-Time)</td>
                        <td><?php echo esc_html($stats['plugin_managed']); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render general settings section
     * 
     * @param array $settings Current settings
     * @return void
     */
    private function renderGeneralSettings(array $settings): void {
        ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="renewal_reminders_enabled">Enable Renewal Reminders</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="renewal_reminders_enabled" 
                                   id="renewal_reminders_enabled"
                                   value="1" 
                                   <?php checked($settings['renewal_reminders_enabled'], true); ?>>
                            Send automated renewal reminders to members
                        </label>
                        <p class="description">
                            When enabled, members without active WC subscriptions will receive email reminders 
                            based on the schedule below.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="renewal_grace_period_days">Grace Period (Days)</label>
                    </th>
                    <td>
                        <input type="number" 
                               name="renewal_grace_period_days" 
                               id="renewal_grace_period_days"
                               value="<?php echo esc_attr($settings['renewal_grace_period_days']); ?>"
                               min="0" 
                               max="90" 
                               class="small-text"> days
                        <p class="description">
                            Members have this many days after their renewal date before their membership is deactivated.
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Render email templates section
     * 
     * @param array $settings Current settings
     * @return void
     */
    private function renderEmailTemplates(array $settings): void {
        $intervals = $this->getNotificationIntervals();
        
        ?>
        <h2>Email Templates</h2>
        <p class="description">
            Configure email subject lines and content for each reminder interval. 
            <strong>Available variables:</strong> <code>{first_name}</code>, <code>{last_name}</code>, 
            <code>{renewal_date}</code>, <code>{days_until_renewal}</code>, <code>{membership_level}</code>
        </p>
        
        <style>
            .renewal-interval-editor {
                background: #f9f9f9;
                padding: 20px;
                margin-bottom: 20px;
                border-left: 4px solid #2271b1;
                border-radius: 4px;
            }
            .renewal-interval-editor h3 {
                margin-top: 0;
                color: #2271b1;
            }
            .renewal-interval-editor label {
                display: block;
                margin-top: 15px;
                font-weight: 600;
            }
            .renewal-interval-editor input[type="text"] {
                width: 100%;
                margin-top: 5px;
            }
        </style>
        
        <?php foreach ($intervals as $interval): ?>
            <div class="renewal-interval-editor">
                <h3><?php echo esc_html($this->getIntervalLabel($interval)); ?></h3>
                
                <label for="renewal_email_subject_<?php echo esc_attr($interval); ?>">
                    Subject Line:
                </label>
                <input type="text" 
                       name="renewal_email_subject_<?php echo esc_attr($interval); ?>" 
                       id="renewal_email_subject_<?php echo esc_attr($interval); ?>"
                       value="<?php echo esc_attr($settings["renewal_email_subject_{$interval}"] ?? ''); ?>"
                       class="large-text">
                
                <label for="renewal_email_content_<?php echo esc_attr($interval); ?>">
                    Email Content:
                </label>
                <?php 
                wp_editor(
                    $settings["renewal_email_content_{$interval}"] ?? '',
                    "renewal_email_content_{$interval}",
                    [
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny' => true,
                        'textarea_name' => "renewal_email_content_{$interval}"
                    ]
                );
                ?>
            </div>
        <?php endforeach; ?>
        <?php
    }
    
    /**
     * Render test email section
     * 
     * @return void
     */
    private function renderTestEmailSection(): void {
        ?>
        <hr style="margin-top: 40px;">
        <h2>Test Renewal Emails</h2>
        <div class="card">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lgl_test_renewal_email'); ?>
                <input type="hidden" name="action" value="lgl_test_renewal_email">
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="test_interval">Email Interval</label>
                            </th>
                            <td>
                                <select name="test_interval" id="test_interval">
                                    <option value="30">30 Days Before Renewal</option>
                                    <option value="14">14 Days Before Renewal</option>
                                    <option value="7">7 Days Before Renewal</option>
                                    <option value="0">Renewal Day (Today)</option>
                                    <option value="-7">7 Days Overdue</option>
                                    <option value="-30">30 Days Overdue (Inactive)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="test_email">Recipient Email</label>
                            </th>
                            <td>
                                <input type="email" 
                                       name="test_email" 
                                       id="test_email"
                                       placeholder="your-email@example.com" 
                                       class="regular-text"
                                       required>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button('Send Test Email', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Handle save settings form submission
     * 
     * @return void
     */
    public function handleSaveSettings(): void {
        check_admin_referer('lgl_renewal_settings');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $settings = [];
        
        // General settings
        $settings['renewal_reminders_enabled'] = isset($_POST['renewal_reminders_enabled']);
        $settings['renewal_grace_period_days'] = (int) ($_POST['renewal_grace_period_days'] ?? 30);
        
        // Email templates
        $intervals = $this->getNotificationIntervals();
        foreach ($intervals as $interval) {
            $settings["renewal_email_subject_{$interval}"] = sanitize_text_field(
                $_POST["renewal_email_subject_{$interval}"] ?? ''
            );
            $settings["renewal_email_content_{$interval}"] = wp_kses_post(
                $_POST["renewal_email_content_{$interval}"] ?? ''
            );
        }
        
        // Save settings
        $result = $this->settingsManager->update($settings);
        
        // Redirect with message
        $redirect = add_query_arg(
            ['page' => 'lgl-renewal-settings', 'updated' => $result ? 'true' : 'false'],
            admin_url('admin.php')
        );
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Handle test email form submission
     * 
     * @return void
     */
    public function handleTestEmail(): void {
        check_admin_referer('lgl_test_renewal_email');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $test_email = sanitize_email($_POST['test_email'] ?? '');
        $test_interval = (int) ($_POST['test_interval'] ?? 7);
        
        $result = false;
        if ($this->mailer && is_email($test_email)) {
            $result = $this->mailer->sendTestEmail($test_email, $test_interval);
        }
        
        // Redirect with message
        $redirect = add_query_arg(
            [
                'page' => 'lgl-renewal-settings', 
                'test_sent' => $result ? 'true' : 'false',
                'test_email' => urlencode($test_email)
            ],
            admin_url('admin.php')
        );
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Get notification intervals
     * 
     * @return array Interval values
     */
    private function getNotificationIntervals(): array {
        return [30, 14, 7, 0, -7, -30];
    }
    
    /**
     * Get human-readable label for interval
     * 
     * @param int $interval Interval in days
     * @return string Label
     */
    private function getIntervalLabel(int $interval): string {
        $labels = [
            30 => '30 Days Before Renewal',
            14 => '14 Days Before Renewal',
            7 => '7 Days Before Renewal',
            0 => 'Renewal Day (Today)',
            -7 => '7 Days Overdue',
            -30 => '30 Days Overdue (Account Inactive)'
        ];
        
        return $labels[$interval] ?? "{$interval} Days";
    }
}

