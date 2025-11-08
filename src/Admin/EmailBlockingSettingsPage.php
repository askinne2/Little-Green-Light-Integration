<?php
/**
 * Email Blocking Settings Page
 * 
 * Admin interface for managing email blocking configuration, whitelist, and viewing blocked emails log.
 * 
 * @package UpstateInternational\LGL
 * @since 2.2.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Email\EmailBlocker;

/**
 * EmailBlockingSettingsPage Class
 * 
 * Renders and handles the email blocking settings interface
 */
class EmailBlockingSettingsPage {
    
    /**
     * Settings manager
     * 
     * @var SettingsManager
     */
    private SettingsManager $settingsManager;
    
    /**
     * Operational data manager
     * 
     * @var OperationalDataManager
     */
    private OperationalDataManager $operationalData;
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Email blocker instance
     * 
     * @var EmailBlocker
     */
    private EmailBlocker $emailBlocker;
    
    /**
     * Constructor
     * 
     * @param SettingsManager $settingsManager Settings manager instance
     * @param OperationalDataManager $operationalData Operational data manager instance
     * @param Helper $helper Helper instance
     * @param EmailBlocker $emailBlocker Email blocker instance
     */
    public function __construct(
        SettingsManager $settingsManager,
        OperationalDataManager $operationalData,
        Helper $helper,
        EmailBlocker $emailBlocker
    ) {
        $this->settingsManager = $settingsManager;
        $this->operationalData = $operationalData;
        $this->helper = $helper;
        $this->emailBlocker = $emailBlocker;
    }
    
    /**
     * Render the settings page
     * 
     * @return void
     */
    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        
        // Handle form submissions
        $this->handleFormSubmissions();
        
        $settings = $this->settingsManager->getAll();
        $blocked_emails = $this->operationalData->getBlockedEmails();
        $blocked_emails = array_reverse($blocked_emails); // Show newest first
        $status = $this->emailBlocker->getBlockingStatus();
        
        ?>
        <div class="wrap">
            <h1>Email Blocking Configuration</h1>
            
            <?php 
            // Display any settings errors/notices
            settings_errors('lgl_email_blocking');
            
            // Also check for transient notice (backup)
            $notice = get_transient('lgl_email_blocking_notice');
            if ($notice) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
                delete_transient('lgl_email_blocking_notice');
            }
            ?>
            
            <?php $this->renderStatusSection($status); ?>
            
            <?php $this->renderSettingsForm($settings); ?>
            
            <?php $this->renderWhitelistSection($settings); ?>
            
            <?php $this->renderBlockedEmailsLog($blocked_emails); ?>
            
            <?php $this->renderTestingTools(); ?>
        </div>
        <?php
    }
    
    /**
     * Handle form submissions
     * 
     * @return void
     */
    private function handleFormSubmissions(): void {
        // Handle force blocking toggle
        if (isset($_POST['save_email_blocking']) && check_admin_referer('lgl_email_blocking_settings')) {
            $force_blocking = isset($_POST['force_email_blocking']);
            
            // Debug logging
            error_log('EmailBlockingSettingsPage: Saving force_email_blocking = ' . ($force_blocking ? 'true' : 'false'));
            
            $result = $this->settingsManager->set('force_email_blocking', $force_blocking);
            
            // Debug logging
            error_log('EmailBlockingSettingsPage: Save result = ' . ($result ? 'success' : 'failed'));
            
            // Verify it was saved
            $saved_value = $this->settingsManager->get('force_email_blocking');
            error_log('EmailBlockingSettingsPage: Saved value = ' . ($saved_value ? 'true' : 'false'));
            
            $message = $force_blocking 
                ? 'Email blocking enabled. All outgoing emails will now be blocked.' 
                : 'Manual override disabled. Email blocking will be determined by environment detection.';
            
            // Use WordPress admin notices API for persistent notices
            add_settings_error(
                'lgl_email_blocking',
                'lgl_email_blocking_updated',
                $message,
                'success'
            );
            
            set_transient('lgl_email_blocking_notice', $message, 30);
        }
        
        // Handle whitelist updates
        if (isset($_POST['save_whitelist']) && check_admin_referer('lgl_email_whitelist')) {
            $whitelist_input = sanitize_textarea_field($_POST['email_whitelist'] ?? '');
            $emails = array_filter(array_map('trim', explode("\n", $whitelist_input)));
            $valid_emails = array_filter($emails, 'is_email');
            
            $this->settingsManager->set('email_whitelist', $valid_emails);
            
            $message = sprintf('Whitelist updated. %d valid email address(es) saved.', count($valid_emails));
            add_settings_error(
                'lgl_email_blocking',
                'lgl_whitelist_updated',
                $message,
                'success'
            );
        }
        
        // Handle clear blocked emails
        if (isset($_POST['clear_blocked_emails']) && check_admin_referer('lgl_clear_blocked_emails')) {
            $this->operationalData->clearBlockedEmails();
            add_settings_error(
                'lgl_email_blocking',
                'lgl_emails_cleared',
                'Blocked emails log cleared.',
                'success'
            );
        }
    }
    
    /**
     * Render status section
     * 
     * @param array $status Blocking status information
     * @return void
     */
    private function renderStatusSection(array $status): void {
        ?>
        <div class="notice notice-info">
            <h3>Current Status</h3>
            <table class="widefat" style="max-width: 600px;">
                <tr>
                    <th style="width: 200px;">Environment</th>
                    <td><?php echo esc_html($status['environment_info']); ?></td>
                </tr>
                <tr>
                    <th>Development Environment</th>
                    <td>
                        <?php if ($status['is_development']): ?>
                            <span style="color: #d63638;">✗ Detected</span> (blocking active)
                        <?php else: ?>
                            <span style="color: #00a32a;">✓ Production</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Manual Override</th>
                    <td>
                        <?php if ($status['is_force_blocking']): ?>
                            <span style="color: #d63638;"><strong>✓ ENABLED</strong></span>
                        <?php else: ?>
                            <span style="color: #999;">Disabled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Temporary Pause</th>
                    <td>
                        <?php if ($status['is_temporarily_disabled']): ?>
                            <span style="color: #d63638;">⏸ Paused</span> (via testing tools)
                        <?php else: ?>
                            <span style="color: #999;">Not active</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><strong>Blocking Status</strong></th>
                    <td>
                        <?php if ($status['is_actively_blocking']): ?>
                            <span style="color: #d63638; font-weight: bold;">🚫 ACTIVE - Emails are being blocked</span>
                        <?php else: ?>
                            <span style="color: #00a32a; font-weight: bold;">✓ INACTIVE - Emails are being sent normally</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p><em>Admin email (<?php echo esc_html(get_option('admin_email')); ?>) is always whitelisted when blocking is active.</em></p>
        </div>
        <?php
    }
    
    /**
     * Render settings form
     * 
     * @param array $settings Current settings
     * @return void
     */
    private function renderSettingsForm(array $settings): void {
        $force_checked = !empty($settings['force_email_blocking']) ? 'checked="checked"' : '';
        ?>
        <div class="card">
            <h2>Email Blocking Control</h2>
            <form method="post">
                <?php wp_nonce_field('lgl_email_blocking_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Force Block All Emails</th>
                        <td>
                            <label>
                                <input type="checkbox" name="force_email_blocking" value="1" <?php echo $force_checked; ?>>
                                <strong>Override environment detection and block all outgoing emails</strong>
                            </label>
                            <p class="description">
                                When enabled, <strong>every email</strong> sent via <code>wp_mail()</code> will be blocked regardless of environment detection. 
                                Use this for staging sites, production testing, or any time you need a hard stop on outgoing mail.
                                Whitelisted addresses (including admin email) will still be allowed through.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Blocking Settings', 'primary', 'save_email_blocking'); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render whitelist section
     * 
     * @param array $settings Current settings
     * @return void
     */
    private function renderWhitelistSection(array $settings): void {
        $whitelist = $settings['email_whitelist'] ?? [];
        $whitelist_text = implode("\n", $whitelist);
        ?>
        <div class="card">
            <h2>Email Whitelist</h2>
            <p>Email addresses in the whitelist will always be allowed through, even when blocking is active.</p>
            <p><strong>Note:</strong> The admin email (<?php echo esc_html(get_option('admin_email')); ?>) is <em>automatically whitelisted</em> and does not need to be added here.</p>
            
            <form method="post">
                <?php wp_nonce_field('lgl_email_whitelist'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Whitelisted Emails</th>
                        <td>
                            <textarea name="email_whitelist" rows="6" class="large-text code"><?php echo esc_textarea($whitelist_text); ?></textarea>
                            <p class="description">Enter one email address per line. Invalid emails will be automatically filtered out.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Whitelist', 'primary', 'save_whitelist'); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render blocked emails log
     * 
     * @param array $blocked_emails Blocked emails array
     * @return void
     */
    private function renderBlockedEmailsLog(array $blocked_emails): void {
        ?>
        <div class="card">
            <h2>Blocked Emails Log</h2>
            
            <?php if (empty($blocked_emails)): ?>
                <p>No emails have been blocked yet.</p>
            <?php else: ?>
                <p><strong><?php echo count($blocked_emails); ?></strong> emails blocked (showing last <?php echo min(count($blocked_emails), 50); ?>).</p>
                
                <form method="post" style="margin-bottom: 15px;">
                    <?php wp_nonce_field('lgl_clear_blocked_emails'); ?>
                    <input type="submit" name="clear_blocked_emails" value="Clear Log" class="button button-secondary" 
                           onclick="return confirm('Are you sure you want to clear the blocked emails log?');">
                </form>
                
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Date/Time</th>
                            <th style="width: 200px;">To</th>
                            <th style="width: 250px;">Subject</th>
                            <th>Message Preview</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked_emails as $email): ?>
                            <tr>
                                <td><?php echo esc_html($email['timestamp']); ?></td>
                                <td><?php echo esc_html(is_array($email['to']) ? implode(', ', $email['to']) : $email['to']); ?></td>
                                <td><strong><?php echo esc_html($email['subject']); ?></strong></td>
                                <td><?php echo esc_html($email['message_preview']); ?>...</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render testing tools section
     * 
     * @return void
     */
    private function renderTestingTools(): void {
        ?>
        <div class="card">
            <h2>Testing Tools</h2>
            <p>Need to test the renewal email system?</p>
            <p>
                <a href="<?php echo admin_url('admin.php?page=lgl-test-renewals'); ?>" class="button button-primary">
                    🧪 Open Membership Testing Tools
                </a>
            </p>
            <p class="description">
                The testing tools provide dry-run reports, email previews, and the ability to send test emails to your admin address safely.
            </p>
        </div>
        <?php
    }
}

