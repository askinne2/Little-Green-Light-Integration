<?php
/**
 * Membership Testing Utility
 * 
 * Provides safe testing tools for renewal system in development environments.
 * Includes dry-run mode, email previews, and test reports without sending live emails.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WPUsers;
use UpstateInternational\LGL\Memberships\RenewalStrategyManager;
use UpstateInternational\LGL\Memberships\MembershipRenewalManager;
use UpstateInternational\LGL\Memberships\MembershipNotificationMailer;

/**
 * Membership Testing Utility Class
 * 
 * Provides tools for testing renewal logic without sending emails
 */
class MembershipTestingUtility {
    private Helper $helper;
    private WPUsers $wpUsers;
    private RenewalStrategyManager $strategyManager;
    private MembershipRenewalManager $renewalManager;
    private MembershipNotificationMailer $mailer;
    
    const SHORTCODE = 'lgl_test_renewals';
    
    public function __construct(
        Helper $helper,
        WPUsers $wpUsers,
        RenewalStrategyManager $strategyManager,
        MembershipRenewalManager $renewalManager,
        MembershipNotificationMailer $mailer
    ) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
        $this->strategyManager = $strategyManager;
        $this->renewalManager = $renewalManager;
        $this->mailer = $mailer;
        
        // Register shortcode for admin testing page
        add_shortcode(self::SHORTCODE, [$this, 'renderTestingShortcode']);
        
        // AJAX handlers for testing
        add_action('wp_ajax_lgl_test_renewal_dry_run', [$this, 'handleDryRunTest']);
        add_action('wp_ajax_lgl_test_email_preview', [$this, 'handleEmailPreview']);
        add_action('wp_ajax_lgl_test_send_to_admin', [$this, 'handleSendTestToAdmin']);
    }
    
    /**
     * Render testing shortcode interface
     */
    public function renderTestingShortcode($atts): string {
        if (!current_user_can('manage_options')) {
            return '<div class="notice notice-error"><p>Access Denied: Admin only.</p></div>';
        }
        
        ob_start();
        ?>
        <div class="wrap lgl-testing-utility">
            <h1>🧪 Membership Renewal Testing Tools</h1>
            
            <div class="notice notice-info">
                <p><strong>Safe Testing Environment</strong></p>
                <p>These tools allow you to test renewal logic without sending emails to real members.</p>
                <p><strong>Environment:</strong> <?php echo esc_html($_SERVER['HTTP_HOST'] ?? 'Unknown'); ?></p>
            </div>
            
            <!-- Tab Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="#dry-run" class="nav-tab nav-tab-active" onclick="switchTab(event, 'dry-run')">Dry Run Report</a>
                <a href="#email-preview" class="nav-tab" onclick="switchTab(event, 'email-preview')">Email Preview</a>
                <a href="#test-specific" class="nav-tab" onclick="switchTab(event, 'test-specific')">Test Specific User</a>
                <a href="#admin-test" class="nav-tab" onclick="switchTab(event, 'admin-test')">Send to Admin</a>
            </h2>
            
            <!-- Tab 1: Dry Run Report -->
            <div id="dry-run" class="tab-content" style="display: block;">
                <h2>Dry Run: What Would Happen Today?</h2>
                <p>This simulates the daily cron job without sending any emails. See exactly what would happen.</p>
                
                <button class="button button-primary" onclick="runDryRun()">🔍 Run Dry Run Test</button>
                
                <div id="dry-run-results" style="margin-top: 20px;"></div>
            </div>
            
            <!-- Tab 2: Email Preview -->
            <div id="email-preview" class="tab-content" style="display: none;">
                <h2>Preview Renewal Emails</h2>
                <p>Preview what renewal emails look like at different intervals.</p>
                
                <table class="form-table">
                    <tr>
                        <th>Select User (Optional)</th>
                        <td>
                            <?php $this->renderUserDropdown(); ?>
                            <p class="description">Leave blank to use sample data</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Email Interval</th>
                        <td>
                            <select id="preview-interval">
                                <option value="30">30 Days Before</option>
                                <option value="14">14 Days Before</option>
                                <option value="7">7 Days Before</option>
                                <option value="0">Renewal Day</option>
                                <option value="-7">7 Days Overdue</option>
                                <option value="-30">30 Days Overdue (Inactive)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <button class="button button-primary" onclick="previewEmail()">👁️ Preview Email</button>
                
                <div id="email-preview-results" style="margin-top: 20px;"></div>
            </div>
            
            <!-- Tab 3: Test Specific User -->
            <div id="test-specific" class="tab-content" style="display: none;">
                <h2>Test Specific User</h2>
                <p>Run renewal logic for a specific user to see what would happen.</p>
                
                <table class="form-table">
                    <tr>
                        <th>Select User</th>
                        <td>
                            <?php $this->renderUserDropdown('test-user-id'); ?>
                        </td>
                    </tr>
                </table>
                
                <button class="button button-primary" onclick="testSpecificUser()">🔬 Test User</button>
                
                <div id="test-specific-results" style="margin-top: 20px;"></div>
            </div>
            
            <!-- Tab 4: Send to Admin -->
            <div id="admin-test" class="tab-content" style="display: none;">
                <h2>Send Test Email to Your Address</h2>
                <p>Actually send a test renewal email to your admin email address.</p>
                
                <table class="form-table">
                    <tr>
                        <th>Your Email</th>
                        <td>
                            <input type="email" id="admin-test-email" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Email Interval</th>
                        <td>
                            <select id="admin-test-interval">
                                <option value="30">30 Days Before</option>
                                <option value="14">14 Days Before</option>
                                <option value="7">7 Days Before</option>
                                <option value="0">Renewal Day</option>
                                <option value="-7">7 Days Overdue</option>
                                <option value="-30">30 Days Overdue (Inactive)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Use Real User Data</th>
                        <td>
                            <?php $this->renderUserDropdown('admin-test-user'); ?>
                            <p class="description">Or leave blank for sample data</p>
                        </td>
                    </tr>
                </table>
                
                <button class="button button-primary" onclick="sendTestToAdmin()">📧 Send Test Email</button>
                
                <div id="admin-test-results" style="margin-top: 20px;"></div>
            </div>
            
            <style>
                .tab-content { display: none; padding: 20px; background: #fff; border: 1px solid #ccc; border-top: none; }
                .nav-tab-active { background: #fff !important; border-bottom: 1px solid #fff !important; }
                .renewal-report { margin-top: 20px; }
                .renewal-report table { margin-top: 10px; }
                .user-detail { background: #f9f9f9; padding: 15px; margin: 10px 0; border-left: 4px solid #2271b1; }
                .email-preview-box { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; margin-top: 20px; }
                .email-preview-box iframe { width: 100%; min-height: 400px; border: none; background: #fff; }
            </style>
            
            <script>
            function switchTab(evt, tabName) {
                // Hide all tabs
                var tabs = document.getElementsByClassName('tab-content');
                for (var i = 0; i < tabs.length; i++) {
                    tabs[i].style.display = 'none';
                }
                
                // Remove active class
                var navTabs = document.getElementsByClassName('nav-tab');
                for (var i = 0; i < navTabs.length; i++) {
                    navTabs[i].classList.remove('nav-tab-active');
                }
                
                // Show current tab
                document.getElementById(tabName).style.display = 'block';
                evt.currentTarget.classList.add('nav-tab-active');
            }
            
            function runDryRun() {
                var results = document.getElementById('dry-run-results');
                results.innerHTML = '<p>🔄 Running dry run test... (this may take a moment)</p>';
                
                jQuery.post(ajaxurl, {
                    action: 'lgl_test_renewal_dry_run',
                    _wpnonce: '<?php echo wp_create_nonce('lgl_test_renewal_dry_run'); ?>'
                }, function(response) {
                    if (response.success) {
                        results.innerHTML = response.data.html;
                    } else {
                        results.innerHTML = '<div class="notice notice-error"><p>Error: ' + response.data.message + '</p></div>';
                    }
                });
            }
            
            function previewEmail() {
                var results = document.getElementById('email-preview-results');
                var interval = document.getElementById('preview-interval').value;
                var userId = document.getElementById('preview-user-id') ? document.getElementById('preview-user-id').value : '';
                
                results.innerHTML = '<p>🔄 Generating preview...</p>';
                
                jQuery.post(ajaxurl, {
                    action: 'lgl_test_email_preview',
                    interval: interval,
                    user_id: userId,
                    _wpnonce: '<?php echo wp_create_nonce('lgl_test_email_preview'); ?>'
                }, function(response) {
                    if (response.success) {
                        results.innerHTML = response.data.html;
                    } else {
                        results.innerHTML = '<div class="notice notice-error"><p>Error: ' + response.data.message + '</p></div>';
                    }
                });
            }
            
            function testSpecificUser() {
                var results = document.getElementById('test-specific-results');
                var userId = document.getElementById('test-user-id').value;
                
                if (!userId) {
                    results.innerHTML = '<div class="notice notice-error"><p>Please select a user</p></div>';
                    return;
                }
                
                results.innerHTML = '<p>🔄 Testing user...</p>';
                
                jQuery.post(ajaxurl, {
                    action: 'lgl_test_renewal_dry_run',
                    user_id: userId,
                    _wpnonce: '<?php echo wp_create_nonce('lgl_test_renewal_dry_run'); ?>'
                }, function(response) {
                    if (response.success) {
                        results.innerHTML = response.data.html;
                    } else {
                        results.innerHTML = '<div class="notice notice-error"><p>Error: ' + response.data.message + '</p></div>';
                    }
                });
            }
            
            function sendTestToAdmin() {
                var results = document.getElementById('admin-test-results');
                var email = document.getElementById('admin-test-email').value;
                var interval = document.getElementById('admin-test-interval').value;
                var userId = document.getElementById('admin-test-user') ? document.getElementById('admin-test-user').value : '';
                
                if (!email) {
                    results.innerHTML = '<div class="notice notice-error"><p>Please enter an email address</p></div>';
                    return;
                }
                
                results.innerHTML = '<p>📧 Sending test email...</p>';
                
                jQuery.post(ajaxurl, {
                    action: 'lgl_test_send_to_admin',
                    email: email,
                    interval: interval,
                    user_id: userId,
                    _wpnonce: '<?php echo wp_create_nonce('lgl_test_send_to_admin'); ?>'
                }, function(response) {
                    if (response.success) {
                        results.innerHTML = '<div class="notice notice-success"><p>' + response.data.message + '</p></div>';
                    } else {
                        results.innerHTML = '<div class="notice notice-error"><p>Error: ' + response.data.message + '</p></div>';
                    }
                });
            }
            </script>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render user dropdown for testing
     */
    private function renderUserDropdown(string $id = 'preview-user-id'): void {
        $members = get_users([
            'role__in' => ['ui_member', 'ui_patron_owner'],
            'number' => 100,
            'orderby' => 'display_name',
            'order' => 'ASC'
        ]);
        
        echo '<select id="' . esc_attr($id) . '" class="regular-text">';
        echo '<option value="">-- Select User --</option>';
        
        foreach ($members as $member) {
            $renewal_date = get_user_meta($member->ID, 'user-membership-renewal-date', true);
            $renewal_info = $renewal_date ? ' (Renewal: ' . date('Y-m-d', $renewal_date) . ')' : ' (No renewal date)';
            
            echo '<option value="' . esc_attr($member->ID) . '">';
            echo esc_html($member->display_name . ' - ' . $member->user_email . $renewal_info);
            echo '</option>';
        }
        
        echo '</select>';
    }
    
    /**
     * AJAX: Handle dry run test
     */
    public function handleDryRunTest(): void {
        check_ajax_referer('lgl_test_renewal_dry_run');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
            return;
        }
        
        $specific_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : null;
        
        try {
            $report = $this->runDryRunTest($specific_user_id);
            $html = $this->formatDryRunReport($report);
            
            wp_send_json_success(['html' => $html]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Run dry run test
     */
    private function runDryRunTest(?int $specific_user_id = null): array {
        $report = [
            'timestamp' => current_time('mysql'),
            'total_members' => 0,
            'wc_subscription_members' => 0,
            'plugin_managed_members' => 0,
            'notifications_to_send' => [],
            'no_action_needed' => [],
            'errors' => []
        ];
        
        // Get members to test
        if ($specific_user_id) {
            $members = [get_user_by('ID', $specific_user_id)];
            $members = array_filter($members); // Remove false values
        } else {
            $members = get_users(['role__in' => ['ui_member', 'ui_patron_owner']]);
        }
        
        $report['total_members'] = count($members);
        
        foreach ($members as $member) {
            $user_id = $member->ID;
            
            // Check strategy
            $strategy = $this->strategyManager->getRenewalStrategy($user_id);
            
            if ($strategy === RenewalStrategyManager::STRATEGY_WOOCOMMERCE) {
                $report['wc_subscription_members']++;
                continue;
            }
            
            $report['plugin_managed_members']++;
            
            // Get renewal date
            $renewal_date = get_user_meta($user_id, 'user-membership-renewal-date', true);
            
            if (!$renewal_date) {
                $report['no_action_needed'][] = [
                    'user_id' => $user_id,
                    'name' => $member->display_name,
                    'email' => $member->user_email,
                    'reason' => 'No renewal date set'
                ];
                continue;
            }
            
            $days_until_renewal = $this->calculateDaysUntilRenewal($renewal_date);
            
            // Check if this user would get a notification
            $notification_intervals = [30, 14, 7, 0, -7, -30];
            
            if (in_array($days_until_renewal, $notification_intervals)) {
                $report['notifications_to_send'][] = [
                    'user_id' => $user_id,
                    'name' => $member->display_name,
                    'email' => $member->user_email,
                    'renewal_date' => date('Y-m-d', $renewal_date),
                    'days_until_renewal' => $days_until_renewal,
                    'email_type' => $this->getEmailTypeLabel($days_until_renewal),
                    'membership_level' => get_user_meta($user_id, 'user-membership-level', true)
                ];
            } else {
                $report['no_action_needed'][] = [
                    'user_id' => $user_id,
                    'name' => $member->display_name,
                    'email' => $member->user_email,
                    'renewal_date' => date('Y-m-d', $renewal_date),
                    'days_until_renewal' => $days_until_renewal,
                    'reason' => 'Not on notification interval'
                ];
            }
        }
        
        return $report;
    }
    
    /**
     * Format dry run report as HTML
     */
    private function formatDryRunReport(array $report): string {
        ob_start();
        ?>
        <div class="renewal-report">
            <h3>📊 Dry Run Test Results</h3>
            <p><strong>Test Run:</strong> <?php echo esc_html($report['timestamp']); ?></p>
            
            <div class="notice notice-info">
                <p><strong>Summary:</strong></p>
                <ul>
                    <li>Total Members Analyzed: <strong><?php echo $report['total_members']; ?></strong></li>
                    <li>WooCommerce Subscription Members (skipped): <strong><?php echo $report['wc_subscription_members']; ?></strong></li>
                    <li>Plugin-Managed Members: <strong><?php echo $report['plugin_managed_members']; ?></strong></li>
                    <li>Emails That Would Be Sent: <strong><?php echo count($report['notifications_to_send']); ?></strong></li>
                </ul>
            </div>
            
            <?php if (!empty($report['notifications_to_send'])): ?>
            <h4>📧 Emails That Would Be Sent Today:</h4>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Renewal Date</th>
                        <th>Days Until Renewal</th>
                        <th>Email Type</th>
                        <th>Level</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['notifications_to_send'] as $notification): ?>
                    <tr>
                        <td><?php echo esc_html($notification['name']); ?></td>
                        <td><?php echo esc_html($notification['email']); ?></td>
                        <td><?php echo esc_html($notification['renewal_date']); ?></td>
                        <td><?php echo esc_html($notification['days_until_renewal']); ?></td>
                        <td><strong><?php echo esc_html($notification['email_type']); ?></strong></td>
                        <td><?php echo esc_html($notification['membership_level']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="notice notice-success">
                <p>✅ No renewal emails would be sent today.</p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($report['no_action_needed'])): ?>
            <details style="margin-top: 20px;">
                <summary><strong>Members Not Requiring Action (<?php echo count($report['no_action_needed']); ?>)</strong></summary>
                <table class="widefat fixed striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Renewal Date</th>
                            <th>Days Until Renewal</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['no_action_needed'] as $member): ?>
                        <tr>
                            <td><?php echo esc_html($member['name']); ?></td>
                            <td><?php echo esc_html($member['email']); ?></td>
                            <td><?php echo isset($member['renewal_date']) ? esc_html($member['renewal_date']) : '-'; ?></td>
                            <td><?php echo isset($member['days_until_renewal']) ? esc_html($member['days_until_renewal']) : '-'; ?></td>
                            <td><?php echo esc_html($member['reason']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * AJAX: Handle email preview
     */
    public function handleEmailPreview(): void {
        check_ajax_referer('lgl_test_email_preview');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
            return;
        }
        
        $interval = isset($_POST['interval']) ? intval($_POST['interval']) : 30;
        $user_id = isset($_POST['user_id']) && !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
        
        try {
            $html = $this->generateEmailPreview($interval, $user_id);
            wp_send_json_success(['html' => $html]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Generate email preview
     */
    private function generateEmailPreview(int $interval, ?int $user_id = null): string {
        // Get user data or use sample
        if ($user_id) {
            $user = get_user_by('ID', $user_id);
            $first_name = get_user_meta($user_id, 'first_name', true) ?: $user->display_name;
            $last_name = get_user_meta($user_id, 'last_name', true) ?: '';
            $email = $user->user_email;
            $membership_level = get_user_meta($user_id, 'user-membership-level', true) ?: 'Individual';
            $renewal_date = get_user_meta($user_id, 'user-membership-renewal-date', true);
            $renewal_date_formatted = $renewal_date ? date('F j, Y', $renewal_date) : date('F j, Y', strtotime('+1 year'));
        } else {
            $first_name = 'John';
            $last_name = 'Doe';
            $email = 'john.doe@example.com';
            $membership_level = 'Individual';
            $renewal_date_formatted = date('F j, Y', strtotime('+1 year'));
        }
        
        // Create mailer instance with proper data
        $mailer = new MembershipNotificationMailer(
            $this->helper,
            '',
            $this->mailer->getSettingsManager()
        );
        
        // Generate email content
        $subject = $mailer->getSubjectLine($first_name, $interval);
        $content = $mailer->getEmailContent(
            $first_name,
            $last_name,
            $renewal_date_formatted,
            $interval,
            $membership_level
        );
        
        ob_start();
        ?>
        <div class="email-preview-box">
            <h3><?php echo $this->getEmailTypeLabel($interval); ?></h3>
            
            <table class="widefat">
                <tr>
                    <th style="width: 150px;">To:</th>
                    <td><?php echo esc_html($first_name . ' ' . $last_name . ' <' . $email . '>'); ?></td>
                </tr>
                <tr>
                    <th>Subject:</th>
                    <td><strong><?php echo esc_html($subject); ?></strong></td>
                </tr>
                <tr>
                    <th>Days Until Renewal:</th>
                    <td><?php echo esc_html($interval); ?></td>
                </tr>
            </table>
            
            <h4>Email Content:</h4>
            <iframe srcdoc="<?php echo esc_attr($content); ?>"></iframe>
            
            <h4>Raw HTML:</h4>
            <textarea readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($content); ?></textarea>
        </div>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * AJAX: Send test email to admin
     */
    public function handleSendTestToAdmin(): void {
        check_ajax_referer('lgl_test_send_to_admin');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied']);
            return;
        }
        
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $interval = isset($_POST['interval']) ? intval($_POST['interval']) : 30;
        $user_id = isset($_POST['user_id']) && !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
        
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Invalid email address']);
            return;
        }
        
        try {
            $result = $this->sendTestEmail($email, $interval, $user_id);
            
            if ($result) {
                wp_send_json_success([
                    'message' => "✅ Test email sent successfully to {$email}! Check your inbox (and spam folder)."
                ]);
            } else {
                wp_send_json_error(['message' => 'Email send failed. Check error logs.']);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Send test email to admin
     */
    private function sendTestEmail(string $to_email, int $interval, ?int $user_id = null): bool {
        // Get user data or use sample
        if ($user_id) {
            $user = get_user_by('ID', $user_id);
            $first_name = get_user_meta($user_id, 'first_name', true) ?: $user->display_name;
            $last_name = get_user_meta($user_id, 'last_name', true) ?: '';
            $membership_level = get_user_meta($user_id, 'user-membership-level', true) ?: 'Individual';
            $renewal_date = get_user_meta($user_id, 'user-membership-renewal-date', true);
            $renewal_date_formatted = $renewal_date ? date('F j, Y', $renewal_date) : date('F j, Y', strtotime('+1 year'));
        } else {
            $first_name = 'Test';
            $last_name = 'User';
            $membership_level = 'Individual';
            $renewal_date_formatted = date('F j, Y', strtotime('+1 year'));
        }
        
        // Create mailer
        $mailer = new MembershipNotificationMailer(
            $this->helper,
            '',
            $this->mailer->getSettingsManager()
        );
        
        $subject = '[TEST] ' . $mailer->getSubjectLine($first_name, $interval);
        $content = $mailer->getEmailContent($interval);
        
        // Replace template variables with actual values
        $content = str_replace(
            ['{first_name}', '{last_name}', '{renewal_date}', '{days_until_renewal}', '{membership_level}'],
            [$first_name, $last_name, $renewal_date_formatted, $interval, $membership_level],
            $content
        );
        
        // Add test notice to content
        $test_notice = '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin-bottom: 20px;">';
        $test_notice .= '<strong>🧪 TEST EMAIL</strong><br>';
        $test_notice .= 'This is a test of the renewal notification system.<br>';
        $test_notice .= 'Original recipient would be: ' . ($user_id ? get_user_by('ID', $user_id)->user_email : 'Sample User') . '<br>';
        $test_notice .= 'Email type: ' . $this->getEmailTypeLabel($interval);
        $test_notice .= '</div>';
        
        $content = str_replace('<body', '<body style="margin: 0; padding: 20px;">' . $test_notice, $content);
        
        // Send email
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        return wp_mail($to_email, $subject, $content, $headers);
    }
    
    /**
     * Calculate days until renewal
     */
    private function calculateDaysUntilRenewal(int $renewal_timestamp): int {
        $today = strtotime('today');
        $renewal_date = strtotime(date('Y-m-d', $renewal_timestamp));
        
        return (int) (($renewal_date - $today) / DAY_IN_SECONDS);
    }
    
    /**
     * Get email type label
     */
    private function getEmailTypeLabel(int $days): string {
        switch ($days) {
            case 30:
                return '30 Days Before Renewal';
            case 14:
                return '14 Days Before Renewal';
            case 7:
                return '7 Days Before Renewal';
            case 0:
                return 'Renewal Day';
            case -7:
                return '7 Days Overdue';
            case -30:
                return '30 Days Overdue (Inactive Notice)';
            default:
                return "{$days} Days from Renewal";
        }
    }
}

